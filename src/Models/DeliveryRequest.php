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
                    user_id, pickup_address, pickup_lat, pickup_lng,
                    dropoff_address, dropoff_lat, dropoff_lng,
                    item_name, item_description, item_photo_url,
                    sender_phone, receiver_phone, vehicle_type,
                    offered_price, payer, payment_method, created_at
                ) VALUES (
                    :user_id, :pickup_address, :pickup_lat, :pickup_lng,
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
                AND status NOT IN ('delivered','cancelled')
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

    public function generateQRCode(string $data, int $size = 200): string {
        $encodedData = urlencode($data);
        return "https://chart.googleapis.com/chart?cht=qr&chs={$size}x{$size}&chl={$encodedData}&chld=L|1";
    }

}