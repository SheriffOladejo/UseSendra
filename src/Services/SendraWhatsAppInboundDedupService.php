<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Dedup Meta WhatsApp inbound webhooks by message id (wamid).
 */
final class SendraWhatsAppInboundDedupService
{
    /**
     * @return bool true if this wamid is new and should be processed; false if duplicate
     */
    public static function claim(PDO $db, string $wamid, ?string $userPhone = null): bool
    {
        $wamid = trim($wamid);
        if ($wamid === '') {
            return true;
        }
        if (!self::tableExists($db)) {
            return true;
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO whatsapp_inbound_dedup (wamid, user_phone) VALUES (:wamid, :phone)'
            );
            $stmt->execute([
                'wamid' => $wamid,
                'phone' => $userPhone !== null && $userPhone !== '' ? $userPhone : null,
            ]);

            return true;
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return false;
            }
            error_log('SendraWhatsAppInboundDedup claim: ' . $e->getMessage());

            return true;
        }
    }

    public static function purgeOlderThan(PDO $db, int $days = 7): int
    {
        if (!self::tableExists($db) || $days < 1) {
            return 0;
        }
        $stmt = $db->prepare(
            'DELETE FROM whatsapp_inbound_dedup WHERE received_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    private static function tableExists(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $db->query('SELECT 1 FROM whatsapp_inbound_dedup LIMIT 1');
            $cache = true;
        } catch (\PDOException $e) {
            $cache = false;
        }

        return $cache;
    }

    private static function isDuplicateKey(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        if ($code === '23000') {
            return true;
        }
        $info = $e->errorInfo ?? null;

        return is_array($info) && isset($info[1]) && (int) $info[1] === 1062;
    }
}
