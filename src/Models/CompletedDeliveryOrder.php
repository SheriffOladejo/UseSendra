<?php

namespace App\Models;

use PDO;

class CompletedDeliveryOrder
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<int, array<string, mixed>> $reviews
     */
    public function insertArchive(array $requestRow, array $reviews): int
    {
        $reviewsJson = json_encode($reviews, JSON_UNESCAPED_UNICODE) ?: '[]';
        $snapshot = json_encode($requestRow, JSON_UNESCAPED_UNICODE) ?: '{}';

        $stmt = $this->db->prepare('
            INSERT INTO completed_delivery_orders (
                original_request_id, user_id, driver_id,
                pickup_address, dropoff_address, offered_price,
                item_name, item_description, sender_phone, receiver_phone,
                reviews_json, raw_snapshot
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            (int) $requestRow['id'],
            $requestRow['user_id'] !== null ? (int) $requestRow['user_id'] : null,
            $requestRow['reserved_driver_id'] !== null ? (int) $requestRow['reserved_driver_id'] : null,
            $requestRow['pickup_address'] ?? null,
            $requestRow['dropoff_address'] ?? null,
            $requestRow['offered_price'] ?? null,
            $requestRow['item_name'] ?? null,
            $requestRow['item_description'] ?? null,
            $requestRow['sender_phone'] ?? null,
            $requestRow['receiver_phone'] ?? null,
            $reviewsJson,
            $snapshot,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecentForDriver(int $driverId, int $limit = 10): array
    {
        if ($driverId < 1 || $limit < 1) {
            return [];
        }
        try {
            $stmt = $this->db->prepare('
                SELECT original_request_id, offered_price, pickup_address, dropoff_address, created_at
                FROM completed_delivery_orders
                WHERE driver_id = ?
                ORDER BY created_at DESC
                LIMIT ' . (int) $limit . '
            ');
            $stmt->execute([$driverId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('CompletedDeliveryOrder::listRecentForDriver: ' . $e->getMessage());

            return [];
        }
    }
}
