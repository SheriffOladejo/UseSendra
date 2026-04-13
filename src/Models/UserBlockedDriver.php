<?php

namespace App\Models;

use PDO;

class UserBlockedDriver
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function listBlockedDriverIdsForUser(int $userId): array
    {
        try {
            $stmt = $this->db->prepare('SELECT driver_id FROM user_blocked_drivers WHERE user_id = ?');
            $stmt->execute([$userId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {
            error_log('UserBlockedDriver::listBlockedDriverIdsForUser: ' . $e->getMessage());
            return [];
        }
    }

    public function block(int $userId, int $driverId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO user_blocked_drivers (user_id, driver_id) VALUES (?, ?)'
            );
            return $stmt->execute([$userId, $driverId]);
        } catch (\PDOException $e) {
            error_log('UserBlockedDriver::block: ' . $e->getMessage());
            return false;
        }
    }

    public function isBlocked(int $userId, int $driverId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM user_blocked_drivers WHERE user_id = ? AND driver_id = ? LIMIT 1'
            );
            $stmt->execute([$userId, $driverId]);
            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }
}
