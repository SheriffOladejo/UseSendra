<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * Resolves which WhatsApp business phone_number_id should send outbound messages
 * for v2-initiated flows (stored on pending_transactions.human_description).
 */
final class WhatsAppBotResolver
{
    /**
     * @param array<string, mixed>|null $pendingRow
     */
    public static function originPhoneNumberIdFromPending(?array $pendingRow): ?string
    {
        if ($pendingRow === null) {
            return null;
        }

        $meta = self::decodeMeta($pendingRow);
        if ($meta === null) {
            return null;
        }

        $id = trim((string) ($meta['origin_whatsapp_phone_id'] ?? ''));
        if ($id === '') {
            return null;
        }

        return $id;
    }

    /**
     * Outbound Sendra notifications (payout receipts, etc.) — prefer pending origin bot, then last inbound.
     *
     * @param array<string, mixed>|null $pendingMeta pending_transactions.human_description JSON
     */
    public static function resolvePhoneNumberIdForOutbound(
        \PDO $db,
        string $phone,
        ?array $pendingMeta = null,
        string $fallbackPhoneId = ''
    ): string {
        if (is_array($pendingMeta)) {
            $origin = self::originFromMeta($pendingMeta);
            if ($origin !== null) {
                return $origin;
            }
        }

        $fromLast = self::resolveFromLastInbound($db, $phone);
        if ($fromLast !== null) {
            return $fromLast;
        }

        return $fallbackPhoneId;
    }

    /**
     * Route indexer deposit pings to the bot that started a recent v2 swap or send.
     */
    public static function resolvePhoneNumberIdForDeposit(\PDO $db, string $phone, string $txHash): ?string
    {
        $phone = trim($phone);
        $txHash = trim($txHash);
        if ($phone === '') {
            return null;
        }

        if ($txHash !== '') {
            $fromSwap = self::resolveFromPanoraSwap($db, $phone, $txHash);
            if ($fromSwap !== null) {
                return $fromSwap;
            }

            $fromTransfer = self::resolveFromTransfer($db, $phone, $txHash);
            if ($fromTransfer !== null) {
                return $fromTransfer;
            }
        }

        return self::resolveFromLastInbound($db, $phone);
    }

    /**
     * Stamp the WhatsApp business number the user last messaged (for external deposit routing).
     */
    public static function touchLastInbound(\PDO $db, string $phone, string $phoneNumberId): void
    {
        $phone = trim($phone);
        $phoneNumberId = trim($phoneNumberId);
        if ($phone === '' || $phoneNumberId === '') {
            return;
        }

        try {
            $stmt = $db->prepare(
                'UPDATE users SET last_whatsapp_phone_id = ? WHERE phone_number = ?'
            );
            $stmt->execute([$phoneNumberId, $phone]);
        } catch (\Throwable $e) {
            error_log('WhatsAppBotResolver touchLastInbound: ' . $e->getMessage());
        }
    }

    /**
     * External deposits (exchange → wallet) have no pending tx to match; use last inbound bot.
     */
    private static function resolveFromLastInbound(\PDO $db, string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'SELECT last_whatsapp_phone_id FROM users WHERE phone_number = ? LIMIT 1'
            );
            $stmt->execute([$phone]);
            $id = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('WhatsAppBotResolver resolveFromLastInbound: ' . $e->getMessage());

            return null;
        }

        $id = is_string($id) ? trim($id) : '';

        return $id !== '' ? $id : null;
    }

    private static function resolveFromPanoraSwap(\PDO $db, string $phone, string $txHash): ?string
    {
        try {
            $stmt = $db->prepare(
                "SELECT human_description
                 FROM pending_transactions
                 WHERE user_phone = ?
                   AND tx_type = 'panora_wallet_swap'
                   AND status = 'confirmed'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)
                 ORDER BY created_at DESC
                 LIMIT 10"
            );
            $stmt->execute([$phone]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('WhatsAppBotResolver panora deposit route: ' . $e->getMessage());

            return null;
        }

        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = self::decodeMeta($row);
            if ($meta === null) {
                continue;
            }
            $stored = trim((string) ($meta['panora_swap_tx_hash'] ?? ''));
            if ($stored === '' || !self::txHashMatches($stored, $txHash)) {
                continue;
            }

            $origin = self::originFromMeta($meta);
            if ($origin !== null) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * P2P send: credit notification goes to the recipient; match their wallet + tx hash.
     */
    private static function resolveFromTransfer(\PDO $db, string $recipientPhone, string $txHash): ?string
    {
        $recipientWallet = self::walletForPhone($db, $recipientPhone);
        if ($recipientWallet === null) {
            return null;
        }

        try {
            $stmt = $db->prepare(
                "SELECT human_description, tx_hash, recipient_address, user_phone
                 FROM pending_transactions
                 WHERE tx_type = 'transfer'
                   AND status = 'confirmed'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)
                   AND LOWER(recipient_address) = LOWER(?)
                 ORDER BY created_at DESC
                 LIMIT 10"
            );
            $stmt->execute([$recipientWallet]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('WhatsAppBotResolver transfer deposit route: ' . $e->getMessage());

            return null;
        }

        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $storedHash = trim((string) ($row['tx_hash'] ?? ''));
            $meta = self::decodeMeta($row);
            if ($meta !== null) {
                $metaHash = trim((string) ($meta['transfer_tx_hash'] ?? ''));
                if ($metaHash !== '') {
                    $storedHash = $metaHash;
                }
            }

            if ($storedHash === '' || !self::txHashMatches($storedHash, $txHash)) {
                continue;
            }

            if ($meta === null || empty($meta['aptos_v2'])) {
                continue;
            }

            // P2P to another user: notify on legacy Sendra (recipient may never use Logistics test bot).
            // Self-send / same-wallet: keep deposit on the bot that started the flow.
            $senderPhone = trim((string) ($row['user_phone'] ?? ''));
            if ($senderPhone !== '' && $senderPhone !== $recipientPhone) {
                continue;
            }

            $origin = self::originFromMeta($meta);
            if ($origin !== null) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function originFromMeta(array $meta): ?string
    {
        $origin = trim((string) ($meta['origin_whatsapp_phone_id'] ?? ''));

        return $origin !== '' ? $origin : null;
    }

    private static function walletForPhone(\PDO $db, string $phone): ?string
    {
        try {
            $stmt = $db->prepare('SELECT aptos_address FROM users WHERE phone_number = ? LIMIT 1');
            $stmt->execute([$phone]);
            $addr = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('WhatsAppBotResolver walletForPhone: ' . $e->getMessage());

            return null;
        }

        return is_string($addr) && trim($addr) !== '' ? trim($addr) : null;
    }

    /**
     * @param array<string, mixed> $pendingRow
     * @return array<string, mixed>|null
     */
    private static function decodeMeta(array $pendingRow): ?array
    {
        $raw = $pendingRow['human_description'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $meta = json_decode($raw, true);

        return is_array($meta) ? $meta : null;
    }

    private static function txHashMatches(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return str_ends_with($a, substr($b, -16)) || str_ends_with($b, substr($a, -16));
    }
}
