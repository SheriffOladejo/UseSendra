<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\WhatsAppService;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Support\WhatsAppBotResolver;

/**
 * Daya deposit / funding-account webhooks (Polymarket + Aptos buy/sell).
 */
final class SendraDayaWebhookService
{
    private const DAYA_TX_TYPES = [
        'onramp_daya_polymarket',
        'onramp_daya_aptos',
        'offramp_daya_aptos',
    ];

    public function __construct(private \PDO $db)
    {
    }

    /**
     * Poll Daya deposits for open Aptos buy/sell rows (webhook miss / late indexer).
     */
    public function reconcileOpenRows(int $lookbackHours = 48): int
    {
        $stmt = $this->db->prepare("
            SELECT id, user_phone, tx_type, amount, currency, status, human_description
            FROM pending_transactions
            WHERE tx_type IN ('onramp_daya_aptos', 'offramp_daya_aptos', 'onramp_daya_polymarket')
              AND status IN ('pending', 'confirmed')
              AND created_at > DATE_SUB(NOW(), INTERVAL :hours HOUR)
            ORDER BY created_at DESC
            LIMIT 40
        ");
        $stmt->bindValue('hours', max(1, $lookbackHours), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return 0;
        }

        $daya = new SendraDayaRampService();
        $listed = $daya->listDeposits(null, 50);
        $deposits = !empty($listed['success']) && is_array($listed['deposits'] ?? null) ? $listed['deposits'] : [];
        $done = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = json_decode((string) ($row['human_description'] ?? ''), true);
            if (!is_array($meta)) {
                continue;
            }
            if (!empty($meta['daya_completed_notified']) || !empty($meta['buy_receipt_sent']) || !empty($meta['sell_receipt_sent'])) {
                continue;
            }
            $fa = trim((string) ($meta['daya_funding_account_id'] ?? ''));
            if ($fa === '') {
                continue;
            }
            $hit = null;
            foreach ($deposits as $dep) {
                if (!is_array($dep)) {
                    continue;
                }
                if (trim((string) ($dep['funding_account_id'] ?? '')) !== $fa) {
                    continue;
                }
                $st = strtoupper((string) ($dep['status'] ?? $dep['settlement_status'] ?? ''));
                if ($st === 'COMPLETED') {
                    $hit = $dep;
                    break;
                }
            }
            if ($hit === null) {
                continue;
            }
            $this->handle([
                'id' => 'poll:' . (string) ($hit['id'] ?? $row['id']),
                'event' => 'deposit.completed',
                'data' => $hit,
            ]);
            $done++;
        }

        return $done;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void
    {
        $name = (string) ($event['event'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $pending = $this->findPending($data);
        if ($pending === null) {
            error_log('Sendra Daya webhook: no pending row for ' . $name . ' data_id=' . (string) ($data['id'] ?? ''));

            return;
        }

        $meta = json_decode((string) ($pending['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $phone = (string) ($pending['user_phone'] ?? $meta['user_phone'] ?? '');
        if ($phone === '') {
            return;
        }

        $repo = new PendingTransactionRepository($this->db);
        $eventId = (string) ($event['id'] ?? '');
        if ($eventId !== '' && ($meta['last_daya_webhook_id'] ?? '') === $eventId) {
            return;
        }

        $repo->mergeMeta($pending['id'], [
            'last_daya_webhook_id' => $eventId,
            'last_daya_event' => $name,
            'last_daya_deposit_id' => (string) ($data['id'] ?? ''),
        ]);
        $pending = $repo->findById((string) $pending['id']) ?? $pending;
        $meta = $repo->decodeMeta($pending);

        $txType = (string) ($pending['tx_type'] ?? '');
        if ($txType === 'onramp_daya_aptos') {
            $this->handleAptosBuy($name, $data, $pending, $meta, $phone, $repo);

            return;
        }
        if ($txType === 'offramp_daya_aptos') {
            $this->handleAptosSell($name, $data, $pending, $meta, $phone, $repo);

            return;
        }

        $this->handlePolymarket($name, $data, $pending, $meta, $phone, $repo);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $pending
     * @param array<string, mixed> $meta
     */
    private function handlePolymarket(
        string $name,
        array $data,
        array $pending,
        array $meta,
        string $phone,
        PendingTransactionRepository $repo
    ): void {
        $whatsapp = $this->whatsappFor($phone, $meta);
        $asset = (string) ($meta['destination_asset'] ?? 'USDT');
        $addr = (string) ($meta['destination_address'] ?? $pending['recipient_address'] ?? '');
        $short = strlen($addr) === 42 ? (substr($addr, 0, 8) . '…' . substr($addr, -6)) : $addr;

        if ($name === 'deposit.received' || $name === 'deposit.processing') {
            if (empty($meta['daya_received_notified'])) {
                $repo->mergeMeta($pending['id'], ['daya_received_notified' => true]);
                $whatsapp->sendMessage($phone, "💸 We received your Naira for the Polymarket fund. Sending {$asset} on Polygon…");
            }

            return;
        }
        if ($name === 'deposit.requires_review') {
            $whatsapp->sendMessage(
                $phone,
                "⚠️ Your Polymarket payment is under review (amount or timing may not match). We'll update you, or email support@usesendra.com"
            );

            return;
        }
        if ($name === 'deposit.failed') {
            $repo->updateStatus((string) $pending['id'], 'failed', null);
            $whatsapp->sendMessage($phone, "❌ Polymarket funding failed. If Naira left your bank, contact support@usesendra.com with ref `" . (string) ($meta['daya_funding_account_id'] ?? '') . "`.");

            return;
        }
        if ($name === 'deposit.completed') {
            if (($meta['daya_completed_notified'] ?? false) === true) {
                return;
            }
            $tx = (string) ($data['tx_hash'] ?? $data['transaction_hash'] ?? '');
            $repo->updateStatus((string) $pending['id'], 'confirmed', $tx !== '' ? $tx : null);
            $repo->mergeMeta($pending['id'], ['daya_completed_notified' => true]);
            $recv = (string) ($data['customer_amount']['amount'] ?? $meta['receive_crypto_display'] ?? '');
            $custAsset = (string) ($data['customer_amount']['currency'] ?? $data['asset'] ?? $asset);
            $body = "✅ *USDT sent toward Polymarket*\n\n";
            if ($recv !== '') {
                $body .= "Amount: *{$recv} {$custAsset}*\n";
            }
            $body .= "To: `{$short}`\n\nCheck Polymarket — incoming USDT is converted to pUSD for trading.";
            if ($tx !== '') {
                $body .= "\n\nTx: `{$tx}`";
            }
            $whatsapp->sendMessage($phone, $body);

            return;
        }
        if ($name === 'funding_account.failed') {
            $whatsapp->sendMessage($phone, "❌ That payment account expired. Type *polymarket* to start again.");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $pending
     * @param array<string, mixed> $meta
     */
    private function handleAptosBuy(
        string $name,
        array $data,
        array $pending,
        array $meta,
        string $phone,
        PendingTransactionRepository $repo
    ): void {
        $whatsapp = $this->whatsappFor($phone, $meta);
        $asset = strtoupper((string) ($meta['destination_asset'] ?? $meta['ledger_asset'] ?? $pending['currency'] ?? 'USDC'));
        $ref = (string) ($meta['daya_funding_account_id'] ?? $pending['id'] ?? '');

        if ($name === 'deposit.received' || $name === 'deposit.processing') {
            if (empty($meta['daya_received_notified'])) {
                $repo->mergeMeta($pending['id'], ['daya_received_notified' => true]);
                $whatsapp->sendMessage(
                    $phone,
                    "💸 We received your Naira. Sending *{$asset}* to your Aptos wallet…"
                );
            }

            return;
        }
        if ($name === 'deposit.requires_review') {
            $whatsapp->sendMessage(
                $phone,
                "⚠️ Your buy payment is under review (amount or timing may not match). We'll update you, or email support@usesendra.com"
            );

            return;
        }
        if ($name === 'deposit.failed' || $name === 'funding_account.failed') {
            $repo->updateStatus((string) $pending['id'], 'failed', null);
            $whatsapp->sendMessage(
                $phone,
                "❌ Buy payment failed. If Naira left your bank, contact support@usesendra.com with ref `{$ref}`."
            );

            return;
        }
        if ($name !== 'deposit.completed') {
            return;
        }

        $tx = trim((string) ($data['tx_hash'] ?? $data['transaction_hash'] ?? ''));
        $recv = $this->extractCryptoAmount($data, $meta, $pending);
        SendraIndexerDepositNotifyService::completeBuyOnramp(
            $this->db,
            $whatsapp,
            $pending,
            $recv,
            $asset,
            $tx !== '' ? $tx : null,
            null,
            'daya_webhook'
        );
        $repo->mergeMeta($pending['id'], ['daya_completed_notified' => true]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $pending
     * @param array<string, mixed> $meta
     */
    private function handleAptosSell(
        string $name,
        array $data,
        array $pending,
        array $meta,
        string $phone,
        PendingTransactionRepository $repo
    ): void {
        $whatsapp = $this->whatsappFor($phone, $meta);
        $asset = strtoupper((string) ($meta['asset'] ?? $pending['currency'] ?? 'USDC'));
        $amount = (string) ($pending['amount'] ?? $meta['crypto_amount'] ?? '');
        $ref = (string) ($meta['daya_funding_account_id'] ?? $pending['id'] ?? '');

        if ($name === 'deposit.received' || $name === 'deposit.processing') {
            if (empty($meta['daya_received_notified'])) {
                $repo->mergeMeta($pending['id'], ['daya_received_notified' => true]);
                $whatsapp->sendMessage(
                    $phone,
                    "💸 Received *{$amount} {$asset}*. Converting to Naira…"
                );
            }

            return;
        }
        if ($name === 'deposit.requires_review') {
            $whatsapp->sendMessage(
                $phone,
                "⚠️ Your cash-out is under review. We'll update you, or email support@usesendra.com with ref `{$ref}`."
            );

            return;
        }
        if ($name === 'deposit.failed' || $name === 'funding_account.failed') {
            $repo->updateStatus((string) $pending['id'], 'failed', null);
            $whatsapp->sendMessage(
                $phone,
                "❌ Cash-out failed after the wallet send. Contact support@usesendra.com with ref `{$ref}`."
            );

            return;
        }
        if (!in_array($name, ['deposit.completed', 'transfer.completed', 'payout.completed'], true)) {
            return;
        }
        if (!empty($meta['daya_completed_notified'])) {
            return;
        }

        $receivedNgn = $this->extractNgnAmount($data, $meta);
        $usePool = $this->isIntermediarySell($meta);
        $repo->updateStatus((string) $pending['id'], 'confirmed', null);
        $repo->mergeMeta($pending['id'], [
            'daya_completed_notified' => true,
            'daya_paid_ngn' => $receivedNgn,
        ]);

        if ($usePool) {
            $requested = (float) ($meta['target_receive_ngn'] ?? $meta['sendra_requested_ngn'] ?? 0);
            SendraIntermediaryDisburse::payUserFromPool($this->db, $whatsapp, [
                'user_phone' => $phone,
                'order_label' => $ref !== '' ? $ref : (string) $pending['id'],
                'centiiv_order_id' => $ref,
                'requested_ngn' => $requested,
                'received_ngn' => $receivedNgn,
                'user_account_number' => (string) ($meta['sendra_user_account_number'] ?? $meta['account_number'] ?? ''),
                'user_bank_code' => (string) ($meta['sendra_user_bank_code'] ?? $meta['bank_code'] ?? ''),
                'user_account_name' => (string) ($meta['sendra_user_account_name'] ?? $meta['account_name'] ?? ''),
                'user_bank_name' => (string) ($meta['sendra_user_bank_name'] ?? $meta['bank_name'] ?? ''),
                'sendra_ledger_asset' => $asset,
                'sendra_ledger_amount' => is_numeric($amount) ? (float) $amount : $amount,
                'daya_rate' => (float) ($meta['daya_rate'] ?? 0),
                'centiiv_crypto_received' => is_numeric($amount) ? (float) $amount : null,
                'centiiv_crypto_asset' => $asset,
            ]);

            return;
        }

        $this->sendDirectSellReceipt($whatsapp, $phone, $pending, $meta, $receivedNgn, $data);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function isIntermediarySell(array $meta): bool
    {
        $flag = $meta['sendra_intermediary_payout'] ?? null;
        $requested = (float) ($meta['target_receive_ngn'] ?? $meta['sendra_requested_ngn'] ?? 0);

        return SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()
            && $requested > 0
            && $flag !== '0'
            && $flag !== 0
            && $flag !== false;
    }

    /**
     * @param array<string, mixed> $pending
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $dayaPayload Deposit / transfer webhook `data` (provider fields if any)
     */
    private function sendDirectSellReceipt(
        WhatsAppService $wa,
        string $phone,
        array $pending,
        array $meta,
        float $receivedNgn,
        array $dayaPayload = []
    ): void {
        if (!empty($meta['buy_receipt_sent']) || !empty($meta['sell_receipt_sent'])) {
            return;
        }
        $asset = strtoupper((string) ($meta['asset'] ?? $pending['currency'] ?? 'USDC'));
        $crypto = (float) ($pending['amount'] ?? $meta['crypto_amount'] ?? 0);
        $rate = (float) ($meta['daya_rate'] ?? 0);
        $quoted = (float) ($meta['sendra_quoted_receive_ngn'] ?? 0);
        $amountNgn = $receivedNgn > 0 ? $receivedNgn : $quoted;
        $ref = (string) ($meta['daya_funding_account_id'] ?? $pending['id'] ?? '—');
        $feeFields = SendraCentiivPayoutReceiptFactory::resolveReceiptTransactionFee([
            'centiiv_crypto_received' => $crypto > 0 ? $crypto : null,
            'centiiv_crypto_asset' => $asset,
        ], [], $rate > 0 ? $rate : ($crypto > 0 && $amountNgn > 0 ? $amountNgn / $crypto : null));

        try {
            $path = SendraPayoutReceiptImageService::renderToTempFile([
                'amount_ngn' => $amountNgn > 0 ? $amountNgn : null,
                'transaction_fee_ngn' => $feeFields['transaction_fee_ngn'],
                'transaction_fee_crypto' => $feeFields['transaction_fee_crypto'],
                'transaction_fee_asset' => $feeFields['transaction_fee_asset'],
                'account_name' => (string) ($meta['account_name'] ?? '—'),
                'account_number' => (string) ($meta['account_number'] ?? '—'),
                'bank_name' => (string) ($meta['bank_name'] ?? '—'),
                'withdraw_asset' => $asset,
                'withdraw_amount' => $crypto > 0 ? $crypto : null,
                'display_reference' => $ref,
                'transaction_id' => $ref,
                'sender_name' => SendraCentiivPayoutReceiptFactory::resolveReceiptSenderLabel(
                    false,
                    $dayaPayload,
                    $meta,
                    [],
                    'daya'
                ),
                'completed_at' => SendraCentiivPayoutReceiptFactory::formatReceiptNow('M j, Y g:i A'),
                'conversion_rate' => $rate > 0
                    ? ('₦' . number_format($rate, 2, '.', ','))
                    : SendraCentiivPayoutReceiptFactory::formatConversionRateDisplay(
                        $amountNgn > 0 ? $amountNgn : null,
                        $crypto > 0 ? $crypto : null,
                        $asset,
                        [],
                        $rate > 0 ? ['rate' => $rate] : null
                    ),
            ]);
            if ($path !== null && is_readable($path)) {
                $wa->sendImage($phone, $path, '✅ Sendra — bank payout receipt');
                @unlink($path);
                $repo = new PendingTransactionRepository($this->db);
                $repo->mergeMeta((string) $pending['id'], ['sell_receipt_sent' => true]);
            }
        } catch (\Throwable $e) {
            error_log('Sendra Daya sell receipt: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $pending
     */
    private function extractCryptoAmount(array $data, array $meta, array $pending): float
    {
        $cust = is_array($data['customer_amount'] ?? null) ? $data['customer_amount'] : [];
        $custCur = strtoupper((string) ($cust['currency'] ?? $data['asset'] ?? ''));
        if (isset($cust['amount']) && is_numeric($cust['amount']) && !in_array($custCur, ['NGN', 'NGN_KOBO'], true)) {
            return (float) $cust['amount'];
        }
        foreach (['destination_amount', 'amount_out', 'crypto_amount'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key]) && (float) $data[$key] > 0) {
                return (float) $data[$key];
            }
        }
        $disp = trim((string) ($meta['receive_crypto_display'] ?? $pending['amount'] ?? ''));
        if ($disp !== '' && preg_match('/^([\d.]+)/', $disp, $m)) {
            return (float) $m[1];
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    private function extractNgnAmount(array $data, array $meta): float
    {
        $cust = is_array($data['customer_amount'] ?? null) ? $data['customer_amount'] : [];
        $custCur = strtoupper((string) ($cust['currency'] ?? ''));
        if (isset($cust['amount']) && is_numeric($cust['amount']) && ($custCur === 'NGN' || $custCur === '')) {
            if ($custCur === 'NGN' || (float) $cust['amount'] >= 100) {
                return (float) $cust['amount'];
            }
        }
        foreach (['destination_amount', 'payout_amount', 'ngn_amount'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key]) && (float) $data[$key] > 0) {
                return (float) $data[$key];
            }
        }
        $quoted = (float) ($meta['sendra_quoted_receive_ngn'] ?? $meta['daya_sell_receive_ngn'] ?? 0);

        return $quoted > 0 ? $quoted : 0.0;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function findPending(array $data): ?array
    {
        $ids = [];
        foreach (['funding_account_id', 'id'] as $key) {
            $v = trim((string) ($data[$key] ?? ''));
            if ($v !== '') {
                $ids[] = $v;
            }
        }
        $dest = is_array($data['settlement_destination'] ?? null) ? $data['settlement_destination'] : [];
        $faFromDest = trim((string) ($dest['id'] ?? ''));
        if ($faFromDest !== '') {
            $ids[] = $faFromDest;
        }
        $fa = is_array($data['funding_account'] ?? null) ? $data['funding_account'] : [];
        $faId = trim((string) ($fa['id'] ?? ''));
        if ($faId !== '') {
            $ids[] = $faId;
        }

        foreach (array_unique($ids) as $id) {
            $row = $this->findByJsonField('daya_funding_account_id', $id);
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByJsonField(string $field, string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        $types = "'" . implode("','", self::DAYA_TX_TYPES) . "'";
        $stmt = $this->db->prepare("
            SELECT *
            FROM pending_transactions
            WHERE tx_type IN ({$types})
              AND (
                human_description LIKE :a
                OR human_description LIKE :b
              )
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([
            'a' => '%"' . $field . '":"' . $value . '"%',
            'b' => '%"' . $field . '": "' . $value . '"%',
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function whatsappFor(string $phone, array $meta): WhatsAppService
    {
        $bots = require dirname(__DIR__, 2) . '/config/Bots.php';
        $aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834';
        $phoneId = WhatsAppBotResolver::resolvePhoneNumberIdForOutbound($this->db, $phone, $meta, (string) $aptosPhoneId);
        $token = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
        if (isset($bots[$phoneId]['token']) && is_string($bots[$phoneId]['token']) && $bots[$phoneId]['token'] !== '') {
            $token = $bots[$phoneId]['token'];
        }

        return new WhatsAppService($token, $phoneId);
    }
}
