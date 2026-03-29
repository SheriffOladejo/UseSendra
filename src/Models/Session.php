<?php

namespace App\Models;

use PDO;

class Session
{
    private $db;
    private $table = 'whatsapp_sessions';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function findByPhone($phone)
    {
        try {

            $stmt = $this->db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE phone_number = :phone
            ");

            $stmt->execute(['phone' => $phone]);

            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("Error finding session: " . $e->getMessage());
            return false;
        }
    }

    public function create($phone)
    {
        try {

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                (phone_number, current_step, created_at)
                VALUES
                (:phone, 'start', NOW())
            ");

            $stmt->execute(['phone' => $phone]);

            return $this->db->lastInsertId();

        } catch (\PDOException $e) {
            error_log("Error creating session: " . $e->getMessage());
            return false;
        }
    }

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

            $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE phone_number = :phone";

            $stmt = $this->db->prepare($sql);

            return $stmt->execute($params);

        } catch (\PDOException $e) {
            error_log("Error updating session: " . $e->getMessage());
            return false;
        }
    }

    public function updateStep($phone, $step)
    {
        return $this->update($phone, ['current_step' => $step]);
    }

    public function clear($phone)
    {
        try {

            $stmt = $this->db->prepare("
                DELETE FROM {$this->table}
                WHERE phone_number = :phone
            ");

            return $stmt->execute(['phone' => $phone]);

        } catch (\PDOException $e) {
            error_log("Error clearing session: " . $e->getMessage());
            return false;
        }
    }
}