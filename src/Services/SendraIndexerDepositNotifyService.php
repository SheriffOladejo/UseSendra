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
            return self::notifyBuyDeposit($db, $wa, $buyRow, $depositData, $amount, $currency, $txHash);
        }

        return self::notifyGenericDeposit($wa, $depositData, $amount, $currency, $txHash);
    }

    /**
     * @param array<string, mixed> $depositData
     * @return array{success: bool, suppressed?: bool, kind?: string}
     */
    private static function notifyBuyDeposit(
        \PDO $db,
        WhatsAppService $wa,
        array $buyRow,
        array $depositData,
        float $amount,
        string $currency,
        string $txHash
    ): array {
        $meta = json_decode((string) ($buyRow['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        if (!empty($meta['indexer_deposit_notified'])) {
            $priorTx = trim((string) ($meta['indexer_deposit_tx_hash'] ?? ''));
            if ($priorTx === '' || $priorTx === $txHash) {
                return ['success' => true, 'suppressed' => true, 'kind' => 'buy_already_notified'];
            }
        }

        $phone = trim((string) ($buyRow['user_phone'] ?? $depositData['phone_number'] ?? ''));
        $correlation = trim((string) ($meta['sendra_oneclick_correlation_id'] ?? ''));
        $displayAmt = self::formatCryptoAmount($amount, $currency);

        $metaForReceipt = array_merge($meta, [
            'ledger_amount' => (string) $amount,
            'receive_crypto_display' => $displayAmt,
        ]);

        $receiptSent = false;
        if ($phone !== '' && empty($meta['buy_receipt_sent'])) {
            $receiptSent = SendraBuyReceiptDelivery::trySend($wa, $phone, $metaForReceipt, null);
        }

        $msgOk = false;
        if ($phone !== '') {
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
            'oneclick_onramp_user_notified' => true,
            'indexer_deposit_notified' => true,
            'indexer_deposit_tx_hash' => $txHash,
            'indexer_deposit_at' => gmdate('c'),
            'buy_receipt_sent' => $receiptSent || !empty($meta['buy_receipt_sent']),
        ]);

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
            . " phone={$phone} amount={$displayAmt} receipt=" . ($receiptSent ? '1' : '0')
        );

        return [
            'success' => $msgOk || $phone === '',
            'kind' => 'buy_complete',
        ];
    }

    /**
     * @param array<string, mixed> $depositData
     * @return array{success: bool, kind: string}
     */
    private static function notifyGenericDeposit(
        WhatsAppService $wa,
        array $depositData,
        float $amount,
        string $currency,
        string $txHash
    ): array {
        $phone = trim((string) ($depositData['phone_number'] ?? ''));
        $network = (string) ($depositData['network'] ?? 'Aptos');
        $walletAddress = (string) ($depositData['wallet_address'] ?? 'unknown');
        $senderAddress = (string) ($depositData['sender_address'] ?? 'unknown');
        $explorerUrl = (string) ($depositData['explorer_url'] ?? '');

        $shortSender = strlen($senderAddress) > 20
            ? substr($senderAddress, 0, 6) . '...' . substr($senderAddress, -6)
            : $senderAddress;

        $shortHash = strlen($txHash) > 20
            ? substr($txHash, 0, 10) . '...' . substr($txHash, -8)
            : $txHash;

        $message = "*💰 Crypto Deposit Notification*\n\n" .
            "*Summary:*\nReceived *{$amount} {$currency}* on *{$network}*\n" .
            "From: `{$shortSender}`\n\n" .
            "*Transaction Hash:*\n`{$shortHash}`\n\n" .
            ($explorerUrl !== '' ? "*View In Explorer:*\n{$explorerUrl}\n\n" : '') .
            "✅ Your wallet has been credited successfully!";

        unset($walletAddress);

        $result = $wa->sendMessage($phone, $message);

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
                   AND tx_type = 'onramp_oneclick_centiiv'
                   AND status = 'pending'
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
}
