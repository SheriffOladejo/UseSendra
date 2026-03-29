<?php

namespace App\Models;

use PDO;

class LogisticsUser
{
    private $db;
    private $table = 'users';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Find user by phone
     */
    public function findByPhone($phone)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE phone = :phone");
            $stmt->execute(['phone' => $phone]);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error finding logistics user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find user by ID
     */
    public function findById($id)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id");
            $stmt->execute(['id' => $id]);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error finding logistics user by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new logistics user
     */
    public function create($phone, $whatsappName = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                    (phone, whatsapp_name, signup_channel, created_at)
                VALUES
                    (:phone, :name, 'whatsapp', NOW())
            ");

            $stmt->execute([
                'phone' => $phone,
                'name'  => $whatsappName
            ]);

            return $this->db->lastInsertId();

        } catch (\PDOException $e) {
            error_log("Error creating logistics user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update user fields dynamically
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
            error_log("Error updating logistics user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user exists
     */
    public function exists($phone)
    {
        $user = $this->findByPhone($phone);
        return $user !== false && !empty($user);
    }

    /**
     * Increment order count
     */
    public function incrementOrders($phone)
    {
        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET total_orders = total_orders + 1
                WHERE phone = :phone
            ");

            return $stmt->execute(['phone' => $phone]);

        } catch (\PDOException $e) {
            error_log("Error incrementing user orders: " . $e->getMessage());
            return false;
        }
    }
}