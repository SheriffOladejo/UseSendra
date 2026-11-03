<?php

declare(strict_types=1);

namespace AptosV2\Services\Buy;

use App\Services\SendraBuyRampService;
use App\Services\SendraCentiivRampService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\OnrampPendingRepository;
use AptosV2\Persistence\UserRepository;

/**
 * Buy order creation + virtual-bank delivery (ported from MessageController buy* order methods).
 */
final class OrderService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private OnrampPendingRepository $onrampPending,
        private UserRepository $users,
    ) {
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     * @return array{outcome: string, meta: array<string, mixed>}
     */
    public function createOrderAndSendBankDetails(string $from, array $userRow, array $ctx, bool $sendOpeningNotice = true): array
    {
        $lockDir = dirname(__DIR__, 4) . '/var/sendra_buy_opening';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $lockPath = $lockDir . '/' . preg_replace('/\D+/', '', $from) . '.lock';
        $lockFp = @fopen($lockPath, 'c');
        $hasLock = is_resource($lockFp) && flock($lockFp, LOCK_EX | LOCK_NB);
        if (!$hasLock && is_resource($lockFp)) {
            fclose($lockFp);
            $lockFp = null;

            return $this->tryDeliverBankOrSayBusy($from, $userRow, $ctx);
        }
        if (!$hasLock) {
            error_log('Sendra buy: lock file unavailable for ' . $from . ' at ' . $lockPath . ' — proceeding without dedup');
            $lockFp = null;
        }

        try {
            $buySvc = new SendraBuyRampService();
            $fresh = $this->users->findByPhone($from) ?: $userRow;
            $liveCtx = $this->mergeCtx($ctx, $fresh);
            $liveCtx['opening_started_at'] = time();

            if ($sendOpeningNotice) {
                $this->whatsapp->sendMessage($from, '⏳ Opening your payment account…');
            }
            $destChain = strtolower((string) ($liveCtx['dest_chain'] ?? 'aptos'));
            if ($destChain === 'aptos' && \App\Services\SendraAptosRamp::usesDayaForAptos()) {
                $daya = new \App\Services\SendraDayaAptosService();
                $opened = $daya->createBuyOrder($from, is_array($fresh) ? $fresh : $userRow, $liveCtx);
                if (empty($opened['success'])) {
                    $this->whatsapp->sendMessage(
                        $from,
                        (string) ($opened['user_message'] ?? '❌ Could not open a payment account. Try again shortly.')
                    );

                    return [
                        'outcome' => 'transition',
                        'flow' => 'buy',
                        'step' => 'await_quote_confirm',
                        'patch' => array_diff_key($liveCtx, ['opening_started_at' => true]),
                        'meta' => ['action' => 'buy_order_failed'],
                    ];
                }
                $created = [
                    'success' => true,
                    'error' => null,
                    'centiiv_order_id' => (string) ($opened['meta']['daya_funding_account_id'] ?? ''),
                    'bank' => $opened['bank'] ?? null,
                    'meta' => $opened['meta'] ?? [],
                ];
            } else {
                $created = $buySvc->createBuyOrder($from, (string) ($fresh['aptos_address'] ?? $userRow['aptos_address'] ?? ''), $liveCtx);
            }

            if (!$created['success']) {
                $cachedOrder = SendraCentiivRampService::pullRecentOnrampOrderCreatedForPhone($from);
                if ($cachedOrder !== null) {
                    $bank = SendraCentiivRampService::extractVirtualBankAccount($cachedOrder);
                    $orderId = SendraCentiivRampService::extractOrderId($cachedOrder);
                    if ($orderId !== null && $orderId !== '') {
                        error_log("Sendra buy: using order.created webhook cache for {$from} order={$orderId}");
                        $created = [
                            'success' => true,
                            'error' => null,
                            'centiiv_order_id' => $orderId,
                            'bank' => $bank,
                            'meta' => $this->metaFromCentiivOrderPayload(
                                $cachedOrder,
                                $liveCtx,
                                $from,
                                (string) ($fresh['aptos_address'] ?? '')
                            ),
                        ];
                    }
                }
            }

            if (!$created['success']) {
                $err = (string) ($created['error'] ?? 'order_failed');
                error_log("Sendra buy create order failed {$from}: {$err}");
                $this->whatsapp->sendMessage($from, $this->formatBuyOrderFailure($err, $liveCtx));

                return [
                    'outcome' => 'transition',
                    'flow' => 'buy',
                    'step' => 'await_quote_confirm',
                    'patch' => array_diff_key($liveCtx, ['opening_started_at' => true]),
                    'meta' => ['action' => 'buy_order_failed'],
                ];
            }

            return $this->finalizeOrder($from, $userRow, $liveCtx, $created);
        } finally {
            if (is_resource($lockFp)) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     * @return array{outcome: string, meta: array<string, mixed>, patch?: array<string, mixed>, flow?: string, step?: string}
     */
    public function handleOpeningOrderStep(string $from, array $userRow, array $ctx, string $messageLower): array
    {
        if ($this->tryDeliverBankFromCacheOrPending($from, $ctx)) {
            return ['outcome' => 'complete', 'meta' => ['action' => 'buy_bank_delivered_resume']];
        }

        $startedAt = (int) ($ctx['opening_started_at'] ?? 0);
        $staleSeconds = max(45, (int) ($_ENV['SENDRA_BUY_OPENING_STALE_SECONDS'] ?? getenv('SENDRA_BUY_OPENING_STALE_SECONDS') ?: 90));
        if ($startedAt > 0 && time() - $startedAt < $staleSeconds) {
            $this->whatsapp->sendMessage(
                $from,
                "⏳ Still opening your payment account…\n\nThis usually takes under a minute. We'll send bank details here shortly."
            );

            return ['outcome' => 'stay', 'meta' => ['action' => 'buy_opening_processing']];
        }

        if ($messageLower === 'yes' || $messageLower === 'y' || $messageLower === 'buy_quote_yes' || $startedAt === 0 || time() - $startedAt >= $staleSeconds) {
            $ctx = array_merge($ctx, ['step' => 'await_quote_confirm']);
            unset($ctx['opening_started_at']);
            if ($startedAt > 0 && time() - $startedAt >= $staleSeconds) {
                error_log('Sendra buy: reopening stale order for ' . $from . ' after ' . (time() - $startedAt) . 's');
            }

            return $this->createOrderAndSendBankDetails($from, $userRow, $ctx);
        }

        $this->whatsapp->sendMessage(
            $from,
            "⏳ Your buy is still being set up.\n\nTap *Yes* again in a moment, or type *cancel* to start over."
        );

        return ['outcome' => 'stay', 'meta' => ['action' => 'buy_opening_processing']];
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     * @return array{outcome: string, meta: array<string, mixed>}
     */
    private function tryDeliverBankOrSayBusy(string $from, array $userRow, array $ctx): array
    {
        if ($this->tryDeliverBankFromCacheOrPending($from, $ctx)) {
            return ['outcome' => 'complete', 'meta' => ['action' => 'buy_bank_delivered_parallel']];
        }

        $this->whatsapp->sendMessage(
            $from,
            "⏳ Still opening your payment account…\n\nHang on — bank details will arrive in this chat shortly."
        );

        return ['outcome' => 'stay', 'meta' => ['action' => 'buy_opening_processing']];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function tryDeliverBankFromCacheOrPending(string $from, array $ctx): bool
    {
        $pending = $this->onrampPending->findLatestOpenForPhone($from);
        if ($pending !== null) {
            return $this->sendBankFromPendingRow($from, $pending, $ctx);
        }

        $cachedOrder = SendraCentiivRampService::pullRecentOnrampOrderCreatedForPhone($from, 900);
        if ($cachedOrder === null) {
            return false;
        }

        $bank = SendraCentiivRampService::extractVirtualBankAccount($cachedOrder);
        $orderId = SendraCentiivRampService::extractOrderId($cachedOrder);
        if ($orderId === null || $orderId === '' || $bank === null) {
            return false;
        }

        if ($this->onrampPending->findByCentiivOrderId($orderId) !== null) {
            $this->whatsapp->sendMessage(
                $from,
                "✅ Your buy order is already open.\nOrder: `{$orderId}`\n\nCheck messages above for bank details, or type *menu*."
            );

            return true;
        }

        $fresh = $this->users->findByPhone($from);
        $aptos = is_array($fresh) ? (string) ($fresh['aptos_address'] ?? '') : '';
        $meta = $this->metaFromCentiivOrderPayload($cachedOrder, $ctx, $from, $aptos);
        $this->storeOnrampOrder($from, $ctx, $meta, $aptos);
        $this->sendBankMessage($from, $ctx, $bank, $orderId, $meta);

        error_log("Sendra buy: delivered bank from webhook cache for {$from} order={$orderId}");

        return true;
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $created
     * @return array{outcome: string, meta: array<string, mixed>, patch?: array<string, mixed>, flow?: string, step?: string}
     */
    private function finalizeOrder(string $from, array $userRow, array $ctx, array $created): array
    {
        $bank = $created['bank'];
        $meta = is_array($created['meta'] ?? null) ? $created['meta'] : [];
        $orderId = (string) ($created['centiiv_order_id'] ?? '');

        if ($this->onrampPending->findByCentiivOrderId($orderId) !== null) {
            $this->whatsapp->sendMessage(
                $from,
                "✅ Your buy order is already open.\nOrder: `{$orderId}`\n\nCheck messages above for bank details, or type *menu*."
            );

            return ['outcome' => 'complete', 'meta' => ['action' => 'buy_order_already_open']];
        }

        $aptos = (string) ($userRow['aptos_address'] ?? '');
        $this->storeOnrampOrder($from, $ctx, $meta, $aptos);

        $payDisplay = number_format((float) ($ctx['pay_ngn'] ?? 0), 0, '.', ',');
        $recv = (string) ($ctx['receive_crypto_display'] ?? '—');
        $asset = (string) ($ctx['asset'] ?? 'USDC');

        if ($bank === null) {
            $this->whatsapp->sendMessage(
                $from,
                "✅ *Buy order opened*\n\n" .
                "Order `{$orderId}`\n" .
                "Pay *₦{$payDisplay}* to fund it.\n" .
                "You should receive *≈ {$recv} {$asset}* → {$this->receiveDestLabel($ctx, $meta)} after payment.\n\n" .
                'Bank details were not returned by the provider — check WhatsApp again shortly or contact support@usesendra.com'
            );

            return ['outcome' => 'complete', 'meta' => ['action' => 'buy_order_no_bank']];
        }

        $this->sendBankMessage($from, $ctx, $bank, $orderId, $meta);

        return ['outcome' => 'complete', 'meta' => ['action' => 'buy_bank_sent']];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $meta
     */
    private function storeOnrampOrder(string $from, array $ctx, array $meta, string $aptos): void
    {
        $meta = $this->enrichOnrampMeta($meta, $ctx);
        $meta['aptos_v2'] = true;
        $meta['origin_whatsapp_phone_id'] = $this->whatsapp->getPhoneNumberId();

        $txType = (($meta['kind'] ?? '') === 'daya_aptos_onramp') ? 'onramp_daya_aptos' : 'onramp_oneclick_centiiv';
        $this->onrampPending->store([
            'user_phone' => $from,
            'tx_type' => $txType,
            'amount' => (string) ($ctx['receive_crypto_display'] ?? ''),
            'currency' => (string) ($ctx['asset'] ?? 'USDC'),
            'recipient_address' => (string) (
                $meta['sendra_oneclick_deposit_address']
                ?? $meta['sendra_oneclick_deposit_stellar']
                ?? $meta['solana_destination']
                ?? $ctx['solana_destination']
                ?? $aptos
            ),
            'human_description' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $ctx
     */
    private function sendBankFromPendingRow(string $from, array $row, array $ctx): bool
    {
        $metaRaw = $row['human_description'] ?? '';
        $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $bank = $meta['virtual_bank'] ?? null;
        if (!is_array($bank)) {
            $bank = SendraCentiivRampService::extractVirtualBankAccount($meta);
        }
        $orderId = (string) ($meta['centiiv_order_id'] ?? $meta['daya_funding_account_id'] ?? '');
        if ($orderId === '' || !is_array($bank)) {
            return false;
        }

        $this->sendBankMessage($from, $ctx, $bank, $orderId, $meta);

        return true;
    }

    /**
     * @param array<string, mixed> $bank
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $ctx
     */
    private function sendBankMessage(string $from, array $ctx, array $bank, string $orderId, array $meta): void
    {
        $bank['bank_name'] = SendraCentiivRampService::displayVirtualBankName(
            (string) ($bank['bank_name'] ?? ''),
            isset($bank['bank_code']) ? (string) $bank['bank_code'] : null
        );

        $instruct = (float) ($meta['instruct_ngn'] ?? $ctx['instruct_ngn'] ?? $ctx['pay_ngn'] ?? $meta['pay_ngn'] ?? 0);
        $payDisplay = number_format($instruct > 0 ? $instruct : (float) ($ctx['pay_ngn'] ?? $meta['pay_ngn'] ?? 0), 2, '.', ',');
        $recv = (string) ($ctx['receive_crypto_display'] ?? $meta['receive_crypto_display'] ?? '—');
        $asset = (string) ($ctx['asset'] ?? $meta['ledger_asset'] ?? 'USDC');
        $window = '⏱ Complete payment within *30 minutes*. Late transfers may not settle automatically.';
        $exp = trim((string) ($meta['daya_expires_at'] ?? ''));
        if ($exp !== '') {
            $ts = strtotime($exp);
            if ($ts !== false) {
                $window = '⏱ Pay before *' . date('g:i A', $ts) . '* (Africa/Lagos). Late transfers may not settle automatically.';
            }
        }

        $this->whatsapp->sendMessage(
            $from,
            "🏦 *Pay to complete your buy*\n\n" .
            "Transfer *exactly ₦{$payDisplay}* to:\n\n" .
            "*{$bank['account_name']}*\n" .
            "`{$bank['account_number']}`\n" .
            "*{$bank['bank_name']}*\n\n" .
            "Order: `{$orderId}`\n" .
            "You receive: *≈ {$recv} {$asset}* → {$this->receiveDestLabel($ctx, $meta)}\n\n" .
            $window
        );
    }

    /**
     * @param array<string, mixed> $centiivOrder
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function metaFromCentiivOrderPayload(array $centiivOrder, array $ctx, string $waPhone, string $aptosAddress): array
    {
        $desc = (string) ($centiivOrder['description'] ?? '');
        $oneclickCorrelation = null;
        $memo = null;
        if (preg_match('/\(1Click\s+([0-9a-f-]{36})\)/i', $desc, $m) === 1) {
            $oneclickCorrelation = $m[1];
        }
        if (preg_match('/\bmemo:(\d+)\b/i', $desc, $m) === 1) {
            $memo = $m[1];
        }

        $beneficiary = $centiivOrder['beneficiary'] ?? null;
        $stellarDest = is_array($beneficiary) ? (string) ($beneficiary['address'] ?? '') : '';

        $destChain = strtolower((string) ($ctx['dest_chain'] ?? ''));
        $solDest = trim((string) ($ctx['solana_destination'] ?? ''));
        $skipOneClick = in_array($destChain, ['stellar', 'solana', 'arc'], true);

        return [
            'kind' => 'oneclick_centiiv_onramp',
            'centiiv_order_id' => SendraCentiivRampService::extractOrderId($centiivOrder),
            'centiiv_order_id_hex' => SendraCentiivRampService::extractOrderIdHex($centiivOrder),
            'pay_ngn' => (float) ($centiivOrder['amount'] ?? $ctx['pay_ngn'] ?? 0),
            'pay_ngn_display' => number_format((float) ($centiivOrder['amount'] ?? $ctx['pay_ngn'] ?? 0), 2, '.', ','),
            'ledger_asset' => (string) ($ctx['asset'] ?? 'USDC'),
            'receive_crypto_display' => (string) ($ctx['receive_crypto_display'] ?? ''),
            'user_aptos' => $aptosAddress,
            'sendra_oneclick_correlation_id' => $oneclickCorrelation,
            'sendra_oneclick_deposit_stellar' => $stellarDest !== '' ? $stellarDest : null,
            'sendra_oneclick_deposit_address' => $stellarDest !== '' ? $stellarDest : null,
            'sendra_oneclick_deposit_memo' => $memo,
            'need_oneclick_poll' => !$skipOneClick && (new SendraBuyRampService())->isOneClickBridgeConfigured(),
            'dest_chain' => $destChain !== '' ? $destChain : 'aptos',
            'solana_destination' => $solDest !== '' ? $solDest : null,
            'virtual_bank' => SendraCentiivRampService::extractVirtualBankAccount($centiivOrder),
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $fresh
     * @return array<string, mixed>
     */
    private function mergeCtx(array $ctx, array $fresh): array
    {
        $raw = $fresh['chat_context'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && ($decoded['flow'] ?? '') === 'buy') {
                $ctx = array_merge($decoded, $ctx);
            }
        }

        if (!isset($ctx['stellar_address']) || $ctx['stellar_address'] === '') {
            $ctx['stellar_address'] = (string) ($fresh['stellar_address'] ?? '');
        }
        if (!isset($ctx['stellar_deposit_memo']) || $ctx['stellar_deposit_memo'] === '') {
            $ctx['stellar_deposit_memo'] = (string) ($fresh['stellar_deposit_memo'] ?? '');
        }

        if (!isset($ctx['arc_address']) || $ctx['arc_address'] === '') {
            $ctx['arc_address'] = (string) ($fresh['arc_address'] ?? '');
        }

        return $ctx;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function enrichOnrampMeta(array $meta, array $ctx): array
    {
        $centiivUsdc = (float) ($ctx['centiiv_usdc'] ?? $meta['centiiv_usdc'] ?? 0);
        if ($centiivUsdc > 0) {
            $meta['centiiv_usdc'] = $centiivUsdc;
            $meta['centiiv_crypto_received'] = $centiivUsdc;
            $meta['centiiv_crypto_asset'] = 'USDC';
        }

        $recv = trim((string) ($ctx['receive_crypto_display'] ?? ''));
        if ($recv !== '' && empty($meta['receive_crypto_display'])) {
            $meta['receive_crypto_display'] = $recv;
        }

        $destChain = strtolower((string) ($ctx['dest_chain'] ?? $meta['dest_chain'] ?? ''));
        if ($destChain !== '') {
            $meta['dest_chain'] = $destChain;
            $meta['sendra_dest_chain'] = $destChain;
        }
        $solDest = trim((string) ($ctx['solana_destination'] ?? $meta['solana_destination'] ?? ''));
        if ($solDest !== '') {
            $meta['solana_destination'] = $solDest;
            $meta['user_solana'] = $solDest;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $meta
     */
    private function receiveDestLabel(array $ctx, array $meta): string
    {
        $chain = strtolower((string) ($ctx['dest_chain'] ?? $meta['dest_chain'] ?? $meta['sendra_dest_chain'] ?? ''));
        if ($chain === 'solana') {
            $addr = trim((string) ($ctx['solana_destination'] ?? $meta['solana_destination'] ?? $meta['user_solana'] ?? ''));
            if ($addr !== '') {
                $short = strlen($addr) > 12 ? (substr($addr, 0, 4) . '…' . substr($addr, -4)) : $addr;

                return "your Solana wallet (`{$short}`)";
            }

            return 'your Solana wallet';
        }
        if ($chain === 'stellar') {
            return 'your Sendra Stellar wallet';
        }
        if ($chain === 'arc') {
            return 'your Sendra Arc wallet';
        }

        return 'your Sendra wallet';
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function formatBuyOrderFailure(string $err, array $ctx): string
    {
        $destChain = strtolower((string) ($ctx['dest_chain'] ?? ''));
        if ($err === 'missing_solana_destination') {
            return "❌ We need a Solana address to send USDC to.\n\nPaste it again, or buy to *Aptos* / *Stellar* instead.";
        }
        $looksLikeClassicGOnly = preg_match('/valid stellar public key|must be a g\.|g\.\.\. address/i', $err) === 1;
        if ($destChain === 'stellar' && $looksLikeClassicGOnly) {
            return "❌ Stellar buy isn't available from our payout partner yet.\n\n" .
                "They currently only pay classic Stellar accounts (`G…`). Sendra wallets are smart accounts (`C…`) so you never need XLM or trustlines.\n\n" .
                "We're asking them to pay `C…` addresses. Until then, buy to your *Aptos* wallet, or sell from Stellar if you already have USDC there.\n\n" .
                'support@usesendra.com';
        }

        error_log('Sendra buy order failed: ' . $err);

        return "❌ Could not open a buy order.\n\nTry again or contact support@usesendra.com";
    }
}
