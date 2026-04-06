<?php

namespace App\Models;

use PDO;

class DeliveryOffer
{
    private $db;
    private $table = 'delivery_offers';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function create($requestId, $driverId, $price)
    {
        try {

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                (request_id, driver_id, proposed_price, status, created_at)
                VALUES
                (:request_id, :driver_id, :price, 'pending', NOW())
            ");

            $stmt->execute([
                'request_id' => $requestId,
                'driver_id' => $driverId,
                'price' => $price
            ]);

            return $this->db->lastInsertId();

        } catch (\PDOException $e) {
            error_log("Error creating delivery offer: " . $e->getMessage());
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
            ");

            $stmt->execute(['request_id' => $requestId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("Error finding offers: " . $e->getMessage());
            return false;
        }
    }

        public function findInReviewByRequest($requestId)
        {
            try {

                $stmt = $this->db->prepare("
                    SELECT *
                    FROM {$this->table}
                    WHERE request_id = :request_id
                    AND status = :status
                ");

                $stmt->execute(['request_id' => $requestId, 'status' => 'inreview']);

                return $stmt->fetchAll(PDO::FETCH_ASSOC);

            } catch (\PDOException $e) {
                error_log("Error finding offers: " . $e->getMessage());
                return false;
            }
        }

    public function findByID($offerId)
    {
        try {

            $stmt = $this->db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE id = :offerId
            ");

            $stmt->execute(['offerId' => $offerId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("Error finding offer: " . $e->getMessage());
            return false;
        }
    }

    public function rejectOtherOffers($requestId, $acceptedOfferId)
    {
        $stmt = $this->db->prepare(
            "UPDATE delivery_offers 
            SET status = 'rejected' 
            WHERE request_id = ? AND id != ? AND status = 'pending'"
        );
        $stmt->execute([$requestId, $acceptedOfferId]);
    }

    public function updateStatus($offerId, $status)
    {
        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET status = :status
                WHERE id = :id
            ");

            return $stmt->execute([
                'status' => $status,
                'id' => $offerId
            ]);

        } catch (\PDOException $e) {
            error_log("Error updating offer status: " . $e->getMessage());
            return false;
        }
    }


    public function findRequestsWithPendingOffers(): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT request_id 
            FROM delivery_offers 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function findPendingByRequest($requestId): array
    {
        $stmt = $this->db->prepare("
            SELECT * 
            FROM delivery_offers 
            WHERE request_id = :request_id AND status = 'pending'
        ");
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findExpiredOffers($seconds): array
    {
        $stmt = $this->db->prepare("
            SELECT * 
            FROM delivery_offers 
            WHERE status = 'pending' 
            AND UNIX_TIMESTAMP(created_at) < :expiry_time
        ");
        $expiry = time() - $seconds;
        $stmt->execute(['expiry_time' => $expiry]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

}