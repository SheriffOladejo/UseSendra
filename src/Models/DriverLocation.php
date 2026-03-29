<?php

namespace App\Models;

use PDO;

class DriverLocation
{
    private $db;
    private $table = 'driver_locations';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function updateLocation($driverId, $lat, $lng)
    {
        try {

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                (driver_id, latitude, longitude)
                VALUES
                (:driver_id, :lat, :lng)
                ON DUPLICATE KEY UPDATE
                    latitude = :lat,
                    longitude = :lng,
                    updated_at = NOW()
            ");

            return $stmt->execute([
                'driver_id' => $driverId,
                'lat' => $lat,
                'lng' => $lng
            ]);

        } catch (\PDOException $e) {
            error_log("Error updating driver location: " . $e->getMessage());
            return false;
        }
    }

    public function getNearbyDrivers()
    {
        try {

            $stmt = $this->db->query("
                SELECT *
                FROM {$this->table}
            ");

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("Error fetching driver locations: " . $e->getMessage());
            return false;
        }
    }
}