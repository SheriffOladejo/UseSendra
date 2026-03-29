<?php

namespace App\Models;

use PDO;

class Trip
{
    private $db;
    private $table = 'trips';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function create($requestId, $driverId, $userId)
    {
        try {

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                (request_id, driver_id, user_id, status, created_at)
                VALUES
                (:request_id, :driver_id, :user_id, 'assigned', NOW())
            ");

            $stmt->execute([
                'request_id' => $requestId,
                'driver_id' => $driverId,
                'user_id' => $userId
            ]);

            return $this->db->lastInsertId();

        } catch (\PDOException $e) {
            error_log("Error creating trip: " . $e->getMessage());
            return false;
        }
    }

    public function findByRequest($requestId)
    {
        try {

            $stmt = $this->db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE request_id = :request_id
                LIMIT 1
            ");

            $stmt->execute(['request_id' => $requestId]);

            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("Error finding trip: " . $e->getMessage());
            return false;
        }
    }

    public function updateStatus($tripId, $status)
    {
        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET status = :status
                WHERE id = :id
            ");

            return $stmt->execute([
                'status' => $status,
                'id' => $tripId
            ]);

        } catch (\PDOException $e) {
            error_log("Error updating trip status: " . $e->getMessage());
            return false;
        }
    }
}