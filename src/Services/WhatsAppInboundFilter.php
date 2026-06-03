<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Filters Meta inbound webhooks so the bot does not treat its own outbound copy as user input.
 */
final class WhatsAppInboundFilter
{
    /**
     * Text bodies we send that Meta may echo back on the messages webhook (from = user wa id).
     *
     * @param array<string, mixed> $msg
     */
    public static function isOutboundEchoMessage(array $msg, string $normalizedBody): bool
    {
        if (isset($msg['from_me']) && $msg['from_me'] === true) {
            return true;
        }
        if (isset($msg['is_self']) && $msg['is_self'] === true) {
            return true;
        }

        $body = trim($normalizedBody);
        if ($body === '') {
            return false;
        }

        $needles = [
            'crypto deposit notification',
            'your wallet has been credited successfully',
            'view in explorer',
            'usdt converted',
            'usdc sent',
            'finalizing exchange',
            'bank payout complete',
            'converting *',
            'swapping *',
            'enter your 6-digit pin',
            'getting a rate for',
            'getting a *',
            'getting the best rate',
            'sorry, i didn\'t understand',
            'transaction is already processing',
            'that transaction is already',
            'swap complete',
            'swap failed',
            'panora swap',
            '1click ref:',
            'send *≈ ₦',
            'send *≈',
            'payout to:',
            'wallet debit:',
            'deposit notification',
            'summary:',
            'received *',
            'on *aptos*',
        ];

        foreach ($needles as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Skip deposit WhatsApp pings while user has an in-flight sell / off-ramp (Panora USDC credit is expected).
     *
     * @param array<string, mixed> $depositData
     */
    public static function shouldSuppressDepositWhatsAppNotify(\PDO $db, string $phoneNumber, array $depositData): bool
    {
        $flag = strtolower(trim((string) ($_ENV['SENDRA_SUPPRESS_DEPOSIT_NOTIFY_OFFRAMP'] ?? getenv('SENDRA_SUPPRESS_DEPOSIT_NOTIFY_OFFRAMP') ?: '1')));
        if (in_array($flag, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        $phone = trim($phoneNumber);
        if ($phone === '') {
            return false;
        }

        try {
            $stmt = $db->prepare(
                'SELECT pending_tx_id FROM users WHERE phone_number = ? LIMIT 1'
            );
            $stmt->execute([$phone]);
            $pendingId = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($pendingId !== '') {
                $p = $db->prepare(
                    'SELECT tx_type, status, human_description FROM pending_transactions WHERE id = ? LIMIT 1'
                );
                $p->execute([$pendingId]);
                $row = $p->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && self::isActiveWithdrawalPending($row)) {
                    return true;
                }
            }

            $recent = $db->prepare(
                "SELECT tx_type, status, human_description
                 FROM pending_transactions
                 WHERE user_phone = ?
                   AND tx_type IN ('offramp_panora_oneclick_centiiv', 'offramp_oneclick_centiiv', 'offramp_centiiv')
                   AND status = 'pending'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 20 MINUTE)
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $recent->execute([$phone]);
            $row = $recent->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row) && self::isActiveWithdrawalPending($row)) {
                return true;
            }
        } catch (\Throwable $e) {
            error_log('WhatsAppInboundFilter shouldSuppressDeposit: ' . $e->getMessage());

            return false;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $pendingRow
     */
    private static function isActiveWithdrawalPending(array $pendingRow): bool
    {
        $txType = (string) ($pendingRow['tx_type'] ?? '');
        $offrampTypes = [
            'offramp_panora_oneclick_centiiv',
            'offramp_oneclick_centiiv',
            'offramp_centiiv',
        ];
        if (!in_array($txType, $offrampTypes, true)) {
            return false;
        }

        return (string) ($pendingRow['status'] ?? '') === 'pending';
    }
}
