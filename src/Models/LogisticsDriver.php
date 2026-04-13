<?php

namespace App\Models;

use PDO;

class LogisticsDriver
{
    private $db;
    private $table = 'drivers';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Find driver by phone
     */
    public function findByPhone($phone)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE phone = :phone");
            $stmt->execute(['phone' => $phone]);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error finding driver: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find driver by ID
     */
    public function findById($id)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id");
            $stmt->execute(['id' => $id]);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error finding driver by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Which verification_status values receive outbound job broadcasts from findNearby().
     * Production: approved only.
     * Test: set env LOGISTICS_TEST_COURIERS_ONLY=true → only approved_test (you + friend), no live riders pinged.
     */
    public static function jobBroadcastVerificationStatuses(): array
    {
        $raw = $_ENV['LOGISTICS_TEST_COURIERS_ONLY'] ?? getenv('LOGISTICS_TEST_COURIERS_ONLY') ?: 'false';

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN)
            ? ['approved_test']
            : ['approved'];
    }

    /**
     * Find driver by Distance
     */
    public function findNearby($lat, $lng, $radius = 5)
    {
        $statuses = self::jobBroadcastVerificationStatuses();
        $placeholders = [];
        $params = [
            'lat' => $lat,
            'lng' => $lng,
            'radius' => $radius,
        ];
        foreach ($statuses as $i => $status) {
            $key = 'vs' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }
        $inSql = implode(',', $placeholders);

        $sql = "
    SELECT *,
    (
        6371 * acos(
            cos(radians(:lat))
            * cos(radians(preferred_latitude))
            * cos(radians(preferred_longitude) - radians(:lng))
            + sin(radians(:lat))
            * sin(radians(preferred_latitude))
        )
    ) AS distance
    FROM drivers
    WHERE verification_status IN ({$inSql}) AND status IN ('active')
    AND preferred_latitude IS NOT NULL
    AND preferred_longitude IS NOT NULL
    HAVING distance < :radius
    ORDER BY distance
    LIMIT 10
    ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create new driver
     */
    public function create($phone, $firstName = null, $lastName = null, $vehicleType = null)
    {
        try {

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                    (phone, first_name, last_name, vehicle_type, verification_status, created_at)
                VALUES
                    (:phone, :first_name, :last_name, :vehicle_type, 'pending', NOW())
            ");

            $stmt->execute([
                'phone'        => $phone,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'vehicle_type' => $vehicleType
            ]);

            return $this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log("Error creating driver: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update driver dynamically
     */
    public function update($phone, $data)
    {
        try {

            if (empty($data)) return false;

            $fields = [];
            $params = ['phone' => $phone];

            foreach ($data as $key => $value) {
                $fields[] = "`{$key}` = :{$key}";
                $params[$key] = $value;
            }

            $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE phone = :phone";

            $stmt = $this->db->prepare($sql);

            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("Error updating driver: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set driver online/offline
     */
    public function setOnlineStatus($driverId, $isOnline)
    {
        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET is_online = :online
                WHERE id = :id
            ");

            return $stmt->execute([
                'online' => $isOnline,
                'id'     => $driverId
            ]);
        } catch (\PDOException $e) {
            error_log("Error updating driver online status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if driver exists
     */
    public function exists($phone)
    {
        $driver = $this->findByPhone($phone);
        return $driver !== false && !empty($driver);
    }

    /**
     * Increment completed trips
     */
    public function incrementTrips($driverId)
    {
        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET total_trips = total_trips + 1
                WHERE id = :id
            ");

            return $stmt->execute(['id' => $driverId]);
        } catch (\PDOException $e) {
            error_log("Error incrementing driver trips: " . $e->getMessage());
            return false;
        }
    }
}
