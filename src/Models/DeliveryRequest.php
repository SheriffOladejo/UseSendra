<?php

namespace App\Models;

use PDO;

class DeliveryRequest
{
    private $db;
    private $table = 'delivery_requests';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getDb()
    {
        return $this->db;
    }

    public function findById($id)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id");
            $stmt->execute(['id' => $id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error finding delivery request: " . $e->getMessage());
            return false;
        }
    }

    public function create(array $data)
    {
        try {
            // Merge with defaults to avoid missing placeholders
            $data = array_merge([
                'user_id' => null,
                'marketplace_order_id' => null,
                'store_order_id' => null,
                'pickup_address' => null,
                'pickup_lat' => null,
                'pickup_lng' => null,
                'dropoff_address' => null,
                'dropoff_lat' => null,
                'dropoff_lng' => null,
                'item_name' => null,
                'item_description' => null,
                'item_photo_url' => null,
                'sender_phone' => null,
                'receiver_phone' => null,
                'vehicle_type' => null,
                'offered_price' => null,
                'payer' => null,
                'payment_method' => null
            ], $data);

            $sql = "
                INSERT INTO {$this->table} (
                    user_id, marketplace_order_id, store_order_id,
                    pickup_address, pickup_lat, pickup_lng,
                    dropoff_address, dropoff_lat, dropoff_lng,
                    item_name, item_description, item_photo_url,
                    sender_phone, receiver_phone, vehicle_type,
                    offered_price, payer, payment_method, created_at
                ) VALUES (
                    :user_id, :marketplace_order_id, :store_order_id,
                    :pickup_address, :pickup_lat, :pickup_lng,
                    :dropoff_address, :dropoff_lat, :dropoff_lng,
                    :item_name, :item_description, :item_photo_url,
                    :sender_phone, :receiver_phone, :vehicle_type,
                    :offered_price, :payer, :payment_method, NOW()
                )
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($data);

            return $this->db->lastInsertId();

        } catch (\PDOException $e) {
            error_log("Error creating delivery request: " . $e->getMessage());
            return false;
        }
    }

    public function update($id, $data)
    {
        try {

            if (empty($data)) return false;

            $fields = [];
            $params = ['id' => $id];

            foreach ($data as $key => $value) {
                $fields[] = "`{$key}` = :{$key}";
                $params[$key] = $value;
            }

            $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);

        } catch (\PDOException $e) {
            error_log("Error updating delivery request: " . $e->getMessage());
            return false;
        }
    }

    public function updateStatus($id, $status)
    {
        return $this->update($id, ['status' => $status]);
    }

    public function findActiveByUser($userId)
    {
        try {

            $stmt = $this->db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE user_id = :user_id
                AND status NOT IN ('delivered','cancelled','draft','payment_expired')
                ORDER BY created_at DESC
                LIMIT 1
            ");

            $stmt->execute(['user_id' => $userId]);

            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("Error finding active delivery: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Latest assigned job waiting for sender to confirm rider QR (status driver_assigned).
     */
    public function findAssignedAwaitingPickupConfirm(int $userId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->table}
                WHERE user_id = ? AND status = 'driver_assigned'
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            error_log("findAssignedAwaitingPickupConfirm: " . $e->getMessage());
            return null;
        }
    }

    /**
     * In-transit package waiting for receiver to enter handoff code.
     */
    public function findInTransitForReceiverPhone(string $digits)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->table}
                WHERE status = 'in_transit'
                AND delivery_handoff_code IS NOT NULL
                AND dropoff_confirmed_at IS NULL
                ORDER BY created_at DESC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (self::phoneDigitsMatch($digits, (string) ($row['receiver_phone'] ?? ''))) {
                    return $row;
                }
            }
            return null;
        } catch (\PDOException $e) {
            error_log("findInTransitForReceiverPhone: " . $e->getMessage());
            return null;
        }
    }

    public static function phoneDigitsMatch(string $a, string $b): bool
    {
        $da = preg_replace('/\D/', '', $a);
        $db = preg_replace('/\D/', '', $b);
        if ($da === '' || $db === '') {
            return false;
        }
        if ($da === $db) {
            return true;
        }
        return str_ends_with($da, $db) || str_ends_with($db, $da);
    }

    public function setDriversNotifiedCount(int $requestId, int $count): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->table} SET drivers_notified_count = ? WHERE id = ?"
            );
            return $stmt->execute([$count, $requestId]);
        } catch (\PDOException $e) {
            error_log("setDriversNotifiedCount: " . $e->getMessage());
            return false;
        }
    }

    public function deleteById(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = ? LIMIT 1");
            return $stmt->execute([$id]);
        } catch (\PDOException $e) {
            error_log('DeliveryRequest::deleteById: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sender’s current job: rider assigned or en route (not yet delivered).
     */
    public function findSenderActiveShipment(int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->table}
                WHERE user_id = :uid
                AND status IN ('driver_assigned', 'in_transit')
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\PDOException $e) {
            error_log('findSenderActiveShipment: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Rider’s in-transit job waiting for dropoff confirmation (code).
     */
    /**
     * Rider has accepted (or is en route) — job not yet delivered.
     */
    public function findOpenAssignedJobForDriver(int $driverId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->table}
                WHERE reserved_driver_id = :did
                AND status IN ('driver_assigned', 'in_transit')
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute(['did' => $driverId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\PDOException $e) {
            error_log('findOpenAssignedJobForDriver: ' . $e->getMessage());

            return null;
        }
    }

    public function findDriverInTransitOpen(int $driverId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->table}
                WHERE reserved_driver_id = :did
                AND status = 'in_transit'
                AND delivery_handoff_code IS NOT NULL
                AND dropoff_confirmed_at IS NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute(['did' => $driverId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\PDOException $e) {
            error_log('findDriverInTransitOpen: ' . $e->getMessage());

            return null;
        }
    }

    public function findByAcceptanceReference($reference)
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM delivery_requests
            WHERE acceptance_payment_reference = ?
            LIMIT 1
        ");

        $stmt->execute([$reference]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Match Centiiv webhook metadata.resourceId (e.g. DPL-dd4ac2).
     */
    public function findByCentiivDplId(string $dplId): array|false
    {
        if ($dplId === '') {
            return false;
        }
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->table}
                WHERE centiiv_dpl_id = ?
                LIMIT 1
            ");
            $stmt->execute([$dplId]);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('findByCentiivDplId: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Atomically claim an open job for a rider (prevents two riders accepting the same order).
     * Sets status to driver_payment_pending briefly as a lock row, then caller assigns via wallet.
     *
     * @param list<string> $allowedStatuses
     */
    public function tryAtomicClaimForDriver(
        int $requestId,
        int $driverId,
        array $allowedStatuses = ['searching_driver', 'awaiting_rebroadcast']
    ): bool {
        if ($requestId < 1 || $driverId < 1 || $allowedStatuses === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($allowedStatuses), '?'));
        $params = array_merge([$driverId, $requestId], $allowedStatuses);

        try {
            $sql = "
                UPDATE {$this->table}
                SET reserved_driver_id = ?,
                    status = 'driver_payment_pending',
                    reservation_expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
                WHERE id = ?
                AND status IN ({$placeholders})
                AND (reserved_driver_id IS NULL OR reserved_driver_id = 0)
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount() === 1;
        } catch (\PDOException $e) {
            error_log('tryAtomicClaimForDriver: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Release a claim if wallet assignment did not complete.
     */
    public function releaseClaim(int $requestId, int $driverId): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET status = 'searching_driver',
                    reserved_driver_id = NULL,
                    reservation_expires_at = NULL,
                    acceptance_payment_reference = NULL
                WHERE id = ?
                AND reserved_driver_id = ?
                AND status = 'driver_payment_pending'
            ");
            $stmt->execute([$requestId, $driverId]);
        } catch (\PDOException $e) {
            error_log('releaseClaim: ' . $e->getMessage());
        }
    }

    public function generateQRCode(string $data, int $size = 200): string {
        $encodedData = urlencode($data);
        return "https://chart.googleapis.com/chart?cht=qr&chs={$size}x{$size}&chl={$encodedData}&chld=L|1";
    }

}