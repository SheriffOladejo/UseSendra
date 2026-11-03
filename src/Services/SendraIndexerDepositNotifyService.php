<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aptos indexer deposit → WhatsApp (primary path for all wallet credits, including buy on-ramp).
 */
final class SendraIndexerDepositNotifyService
{
    /**
     * @param array<string, mixed> $depositData phone_number, amount, currency, transaction_hash, explorer_url, …
     * @return array{success: bool, suppressed?: bool, kind?: string}
     */
    public static function handle(\PDO $db, WhatsAppService $wa, array $depositData): array
    {
        $phone = trim((string) ($depositData['phone_number'] ?? ''));
        if ($phone === '') {
            return ['success' => false];
        }

        if (WhatsAppInboundFilter::shouldSuppressDepositWhatsAppNotify($db, $phone, $depositData)) {
            error_log("SendraIndexerDepositNotify: suppressed during off-ramp phone={$phone}");

            return ['success' => true, 'suppressed' => true, 'kind' => 'offramp_suppressed'];
        }

        $txHash = trim((string) ($depositData['transaction_hash'] ?? ''));
        $amount = (float) ($depositData['amount'] ?? 0);
        $currency = strtoupper(trim((string) ($depositData['currency'] ?? 'USDC')));
        if ($currency === '') {
            $currency = 'USDC';
        }

        $buyRow = self::findMatchingOnrampPending($db, $phone, $amount, $currency, $txHash);
        if ($buyRow !== null) {
            $outcome = self::completeBuyOnramp($db, $wa, $buyRow, $amount, $currency, $txHash, null, 'indexer');

            return [
                'success' => $outcome['skipped'] || $outcome['receipt_sent'] || $outcome['message_sent'],
                'suppressed' => $outcome['skipped'],
                'kind' => $outcome['skipped'] ? 'buy_already_notified' : 'buy_complete',
            ];
        }

        return self::notifyGenericDeposit($db, $wa, $depositData, $amount, $currency, $txHash);
    }

    /**
     * Normalize an Aptos address to 0x + 64 hex chars for DB matching.
     */
    private static function normalizeAptosAddress(string $address): string
    {
        $addr = strtolower(trim($address));
        if (strpos($addr, '0x') === 0) {
            $addr = substr($addr, 2);
        }
        if ($addr === '' || !ctype_xdigit($addr)) {
            return '';
        }

        return '0x' . str_pad($addr, 64, '0', STR_PAD_LEFT);
    }

    /**
     * If the sender address belongs to a Sendra user, return their WhatsApp name; otherwise null.
     */
    private static function lookupSenderName(\PDO $db, string $senderAddress): ?string
    {
        $normalized = self::normalizeAptosAddress($senderAddress);
        if ($normalized === '') {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'SELECT username FROM users
                 WHERE LOWER(aptos_address) = LOWER(:addr)
                 LIMIT 1'
            );
            $stmt->execute(['addr' => $normalized]);
            $name = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('SendraIndexerDepositNotify lookupSenderName: ' . $e->getMessage());

            return null;
        }

        $name = is_string($name) ? trim($name) : '';

        return $name !== '' ? $name : null;
    }

    /**
     * WhatsApp completion + receipt for a matched on-ramp row (indexer or 1Click poll fallback).
     *
     * @param array<string, mixed> $buyRow pending_transactions row
     * @param array<string, mixed>|null $oneClickStatusData decoded 1Click /v0/status (optional)
     * @return array{skipped: bool, receipt_sent: bool, message_sent: bool}
     */
    public static function completeBuyOnramp(
        \PDO $db,
        WhatsAppService $wa,
        array $buyRow,
        float $receiveAmount,
        string $currency,
        ?string $aptosTxHash = null,
        ?array $oneClickStatusData = null,
        string $source = 'indexer'
    ): array {
        $meta = json_decode((string) ($buyRow['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $aptosTxHash = $aptosTxHash !== null ? trim($aptosTxHash) : '';
        if ($aptosTxHash === '') {
            $aptosTxHash = null;
        }

        $phone = trim((string) ($buyRow['user_phone'] ?? ''));
        $nativeOnramp = self::skipsOneClickOnrampMeta($meta) || self::isDayaAptosOnrampMeta($meta);

        if (!empty($meta['buy_receipt_sent']) && ($nativeOnramp || !empty($meta['oneclick_onramp_user_notified']))) {
            if ($aptosTxHash === null || trim((string) ($meta['indexer_deposit_tx_hash'] ?? '')) === $aptosTxHash) {
                return ['skipped' => true, 'receipt_sent' => false, 'message_sent' => false];
            }
        }

        if (!empty($meta['indexer_deposit_notified']) && $aptosTxHash !== null) {
            $priorTx = trim((string) ($meta['indexer_deposit_tx_hash'] ?? ''));
            if ($priorTx !== '' && $priorTx === $aptosTxHash && !empty($meta['buy_receipt_sent'])) {
                return ['skipped' => true, 'receipt_sent' => false, 'message_sent' => false];
            }
        }
        $correlation = trim((string) ($meta['sendra_oneclick_correlation_id'] ?? ''));
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $currency = strtoupper(trim((string) ($buyRow['currency'] ?? 'USDC')));
        }
        if ($receiveAmount <= 0) {
            $rowAmt = trim((string) ($buyRow['amount'] ?? ''));
            if ($rowAmt !== '' && is_numeric($rowAmt)) {
                $receiveAmount = (float) $rowAmt;
            }
        }

        $displayAmt = self::formatCryptoAmount($receiveAmount, $currency);
        $metaForReceipt = array_merge($meta, [
            'ledger_amount' => (string) $receiveAmount,
            'receive_crypto_display' => $displayAmt,
            'ledger_asset' => $currency,
        ]);

        $wa = self::whatsappForOnrampMeta($wa, $db, $phone, $meta);

        $receiptSent = false;
        if ($phone !== '' && empty($meta['buy_receipt_sent'])) {
            $receiptSent = SendraBuyReceiptDelivery::trySend($wa, $phone, $metaForReceipt, $oneClickStatusData);
        }

        $msgOk = false;
        if (!$nativeOnramp && $phone !== '' && empty($meta['oneclick_onramp_user_notified'])) {
            $res = $wa->sendMessage(
                $phone,
                "✅ *Crypto received*\n\n" .
                "*{$displayAmt}* is in your Sendra wallet.\n" .
                ($correlation !== '' ? "Ref: `{$correlation}`\n\n" : "\n") .
                'Type *balance* to check.'
            );
            $msgOk = is_array($res) && !empty($res['success']);
        }

        $merge = array_merge($meta, [
            'need_oneclick_poll' => false,
            'oneclick_swap_status' => 'SUCCESS',
            'oneclick_onramp_user_notified' => $nativeOnramp
                || $msgOk
                || !empty($meta['oneclick_onramp_user_notified']),
            'indexer_deposit_notified' => $source === 'indexer' || !empty($meta['indexer_deposit_notified']),
            'buy_receipt_sent' => $receiptSent || !empty($meta['buy_receipt_sent']),
            'onramp_completed_at' => $meta['onramp_completed_at'] ?? gmdate('c'),
            'onramp_completed_via' => $source,
        ]);
        if ($aptosTxHash !== null) {
            $merge['indexer_deposit_tx_hash'] = $aptosTxHash;
            $merge['indexer_deposit_at'] = gmdate('c');
            $merge['indexer_deposit_notified'] = true;
        }

        $u = $db->prepare(
            'UPDATE pending_transactions SET human_description = ?, status = ? WHERE id = ?'
        );
        $u->execute([
            json_encode($merge, JSON_UNESCAPED_UNICODE),
            'confirmed',
            $buyRow['id'],
        ]);

        error_log(
            'SendraIndexerDepositNotify: buy complete id=' . ($buyRow['id'] ?? '')
            . " phone={$phone} source={$source} amount={$displayAmt} receipt=" . ($receiptSent ? '1' : '0')
        );

        return [
            'skipped' => false,
            'receipt_sent' => $receiptSent,
            'message_sent' => $msgOk,
        ];
    }

    /**
     * Native Stellar buys credit via the G+memo indexer, not Aptos/1Click receipt messages.
     *
     * @param array<string, mixed> $meta
     */
    public static function isNativeStellarOnrampMeta(array $meta): bool
    {
        $chain = strtolower(trim((string) ($meta['dest_chain'] ?? $meta['sendra_dest_chain'] ?? '')));

        return $chain === 'stellar';
    }

    /**
     * Direct Solana buys: crypto is paid to the user's pasted address (no Sendra wallet, no 1Click).
     *
     * @param array<string, mixed> $meta
     */
    public static function isDirectSolanaOnrampMeta(array $meta): bool
    {
        $chain = strtolower(trim((string) ($meta['dest_chain'] ?? $meta['sendra_dest_chain'] ?? '')));

        return $chain === 'solana';
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function isNativeArcOnrampMeta(array $meta): bool
    {
        $chain = strtolower(trim((string) ($meta['dest_chain'] ?? $meta['sendra_dest_chain'] ?? '')));

        return $chain === 'arc';
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function skipsOneClickOnrampMeta(array $meta): bool
    {
        return self::isNativeStellarOnrampMeta($meta)
            || self::isDirectSolanaOnrampMeta($meta)
            || self::isNativeArcOnrampMeta($meta);
    }

    /**
     * Daya ONCHAIN Aptos buy — credits the Sendra Aptos wallet, no 1Click hop.
     *
     * @param array<string, mixed> $meta
     */
    public static function isDayaAptosOnrampMeta(array $meta): bool
    {
        $kind = (string) ($meta['kind'] ?? '');
        $chain = strtolower(trim((string) ($meta['dest_chain'] ?? $meta['sendra_dest_chain'] ?? '')));

        return $kind === 'daya_aptos_onramp' || ($chain === 'aptos' && !empty($meta['daya_funding_account_id']) && empty($meta['need_oneclick_poll']));
    }

    /**
     * After Stellar pool credit (or Solana payout notify), send the buy receipt for a matching on-ramp.
     *
     * @return array{skipped: bool, receipt_sent: bool, message_sent: bool}
     */
    public static function completeNativeOnrampCredit(
        \PDO $db,
        WhatsAppService $wa,
        string $phone,
        float $amount,
        string $currency,
        string $chain = 'stellar'
    ): array {
        $chain = strtolower(trim($chain));
        $row = self::findMatchingNativeOnrampPending($db, $phone, $amount, $currency, $chain);
        if ($row === null) {
            return ['skipped' => true, 'receipt_sent' => false, 'message_sent' => false];
        }

        return self::completeBuyOnramp($db, $wa, $row, $amount, $currency, null, null, $chain . '_credit');
    }

    /**
     * @return ?array<string, mixed>
     */
    private static function findMatchingNativeOnrampPending(
        \PDO $db,
        string $phone,
        float $amount,
        string $currency,
        string $chain
    ): ?array {
        try {
            $stmt = $db->prepare(
                "SELECT id, user_phone, human_description, amount, currency, status, created_at
                 FROM pending_transactions
                 WHERE user_phone = ?
                   AND tx_type = 'onramp_oneclick_centiiv'
                   AND status IN ('pending', 'confirmed')
                   AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY created_at DESC
                 LIMIT 8"
            );
            $stmt->execute([$phone]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SendraIndexerDepositNotify find native pending: ' . $e->getMessage());

            return null;
        }
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = json_decode((string) ($row['human_description'] ?? ''), true);
            if (!is_array($meta)) {
                continue;
            }
            if ($chain === 'stellar' && !self::isNativeStellarOnrampMeta($meta)) {
                continue;
            }
            if ($chain === 'solana' && !self::isDirectSolanaOnrampMeta($meta)) {
                continue;
            }
            if ($chain === 'arc' && !self::isNativeArcOnrampMeta($meta)) {
                continue;
            }
            if (!empty($meta['buy_receipt_sent'])) {
                continue;
            }
            if (self::amountMatchesBuy($row, $meta, $amount, $currency)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Backfill receipt / completion for on-ramps where 1Click succeeded but indexer missed the row.
     *
     * @return int rows completed
     */
    public static function backfillMissingBuyReceipts(\PDO $db, WhatsAppService $wa, int $lookbackDays = 7): int
    {
        $done = 0;
        try {
            $stmt = $db->prepare("
                SELECT id, user_phone, human_description, amount, currency, status, created_at
                FROM pending_transactions
                WHERE tx_type IN ('onramp_oneclick_centiiv', 'onramp_daya_aptos')
                  AND status IN ('pending', 'confirmed')
                  AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->bindValue('days', max(1, $lookbackDays), \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SendraIndexerDepositNotify backfill: ' . $e->getMessage());

            return 0;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = json_decode((string) ($row['human_description'] ?? ''), true);
            if (!is_array($meta)) {
                continue;
            }
            if (!empty($meta['buy_receipt_sent'])) {
                continue;
            }
            $swapOk = strtoupper(trim((string) ($meta['oneclick_swap_status'] ?? ''))) === 'SUCCESS';
            $nativePaid = self::skipsOneClickOnrampMeta($meta)
                && (!empty($meta['centiiv_paid_at']) || strtolower(trim((string) ($row['status'] ?? ''))) === 'confirmed');
            $dayaPaid = self::isDayaAptosOnrampMeta($meta)
                && (!empty($meta['daya_completed_notified']) || strtolower(trim((string) ($row['status'] ?? ''))) === 'confirmed');
            if (!$swapOk && empty($meta['centiiv_paid_at']) && !$nativePaid && !$dayaPaid) {
                continue;
            }

            $currency = strtoupper(trim((string) ($row['currency'] ?? 'USDC')));
            $amount = (float) ($row['amount'] ?? 0);
            $txHash = self::findRecentOnrampDepositTxHash($db, (string) ($row['user_phone'] ?? ''), $amount, $currency);

            $outcome = self::completeBuyOnramp(
                $db,
                $wa,
                $row,
                $amount,
                $currency,
                $txHash,
                null,
                'backfill'
            );
            if (!$outcome['skipped'] && ($outcome['receipt_sent'] || $outcome['message_sent'])) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * @return ?string Aptos tx hash when a recent inbound matches the on-ramp amount
     */
    private static function findRecentOnrampDepositTxHash(
        \PDO $db,
        string $phone,
        float $expectedAmount,
        string $currency
    ): ?string {
        if ($phone === '' || $expectedAmount <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare("
                SELECT amount, currency, tx_hash
                FROM transaction_history
                WHERE user_phone = ?
                  AND direction = 'in'
                  AND currency = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$phone, strtoupper($currency)]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $amt = (float) ($row['amount'] ?? 0);
            $tol = max(0.02, $expectedAmount * 0.15);
            if ($amt > 0 && abs($amt - $expectedAmount) <= $tol) {
                $hash = trim((string) ($row['tx_hash'] ?? ''));

                return $hash !== '' ? $hash : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $depositData
     * @return array{success: bool, kind: string}
     */
    private static function notifyGenericDeposit(
        \PDO $db,
        WhatsAppService $wa,
        array $depositData,
        float $amount,
        string $currency,
        string $txHash
    ): array {
        $phone = trim((string) ($depositData['phone_number'] ?? ''));
        $routePhoneId = \AptosV2\Support\WhatsAppBotResolver::resolvePhoneNumberIdForDeposit($db, $phone, $txHash);
        if ($routePhoneId !== null && $routePhoneId !== $wa->getPhoneNumberId()) {
            $token = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
            $wa = new WhatsAppService($token, $routePhoneId);
        }

        $network = (string) ($depositData['network'] ?? 'Aptos');
        $walletAddress = (string) ($depositData['wallet_address'] ?? 'unknown');
        $senderAddress = (string) ($depositData['sender_address'] ?? 'unknown');
        $explorerUrl = (string) ($depositData['explorer_url'] ?? '');

        // Prefer the sender's WhatsApp name when the address belongs to a Sendra user.
        $senderName = self::lookupSenderName($db, $senderAddress);
        if ($senderName !== null) {
            $fromLine = "From: *{$senderName}*";
        } else {
            $shortSender = strlen($senderAddress) > 20
                ? substr($senderAddress, 0, 6) . '...' . substr($senderAddress, -6)
                : $senderAddress;
            $fromLine = "From: `{$shortSender}`";
        }

        $shortHash = strlen($txHash) > 20
            ? substr($txHash, 0, 10) . '...' . substr($txHash, -8)
            : $txHash;

        $message = "*💰 Crypto Deposit Notification*\n\n" .
            "*Summary:*\nReceived *{$amount} {$currency}* on *{$network}*\n" .
            "{$fromLine}\n\n" .
            "*Transaction Hash:*\n`{$shortHash}`\n\n" .
            "✅ Your wallet has been credited successfully!";

        unset($walletAddress);

        if ($explorerUrl !== '' && method_exists($wa, 'sendCTAUrlButton')) {
            $result = $wa->sendCTAUrlButton($phone, $message, 'View in Explorer', $explorerUrl);
        } else {
            $result = $wa->sendMessage($phone, $message);
        }

        return [
            'success' => is_array($result) && !empty($result['success']),
            'kind' => 'generic_deposit',
        ];
    }

    /**
     * @return ?array<string, mixed> pending_transactions row
     */
    private static function findMatchingOnrampPending(
        \PDO $db,
        string $phone,
        float $amount,
        string $currency,
        string $txHash
    ): ?array {
        try {
            $stmt = $db->prepare(
                "SELECT id, user_phone, human_description, amount, currency, status, created_at
                 FROM pending_transactions
                 WHERE user_phone = ?
                   AND tx_type IN ('onramp_oneclick_centiiv', 'onramp_daya_aptos')
                   AND status IN ('pending', 'confirmed')
                   AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)
                 ORDER BY created_at DESC
                 LIMIT 5"
            );
            $stmt->execute([$phone]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SendraIndexerDepositNotify find pending: ' . $e->getMessage());

            return null;
        }

        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = json_decode((string) ($row['human_description'] ?? ''), true);
            if (!is_array($meta)) {
                continue;
            }
            if (self::skipsOneClickOnrampMeta($meta)) {
                continue;
            }
            if (!empty($meta['indexer_deposit_notified'])) {
                $priorTx = trim((string) ($meta['indexer_deposit_tx_hash'] ?? ''));
                if ($priorTx !== '' && $priorTx === $txHash) {
                    continue;
                }
                if (!empty($meta['oneclick_onramp_user_notified'])) {
                    continue;
                }
            }

            if (self::amountMatchesBuy($row, $meta, $amount, $currency)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $meta
     */
    private static function amountMatchesBuy(array $row, array $meta, float $depositAmount, string $currency): bool
    {
        if ($depositAmount <= 0) {
            return true;
        }

        $expected = null;
        $rowAmt = trim((string) ($row['amount'] ?? ''));
        if ($rowAmt !== '' && is_numeric($rowAmt)) {
            $expected = (float) $rowAmt;
        }
        $disp = trim((string) ($meta['receive_crypto_display'] ?? ''));
        if ($expected === null && $disp !== '' && preg_match('/^([\d.]+)/', $disp, $m)) {
            $expected = (float) $m[1];
        }

        if ($expected === null || $expected <= 0) {
            return true;
        }

        $rowCur = strtoupper(trim((string) ($row['currency'] ?? 'USDC')));
        if ($rowCur !== '' && $rowCur !== $currency) {
            return false;
        }

        $tol = max(0.02, $expected * 0.15);

        return abs($depositAmount - $expected) <= $tol;
    }

    private static function formatCryptoAmount(float $amount, string $currency): string
    {
        $s = rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');

        return ($s !== '' ? $s : '0') . ' ' . strtoupper($currency);
    }

    /**
     * Route buy completion + receipt to the WhatsApp number that started the flow (v2 Logistics vs Aptos).
     *
     * @param array<string, mixed> $meta pending_transactions.human_description
     */
    private static function whatsappForOnrampMeta(
        WhatsAppService $defaultWa,
        \PDO $db,
        string $phone,
        array $meta
    ): WhatsAppService {
        $phoneId = \AptosV2\Support\WhatsAppBotResolver::resolvePhoneNumberIdForOutbound(
            $db,
            $phone,
            $meta,
            $defaultWa->getPhoneNumberId()
        );
        if ($phoneId === '' || $phoneId === $defaultWa->getPhoneNumberId()) {
            return $defaultWa;
        }

        $token = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');

        return new WhatsAppService($token, $phoneId);
    }
}
