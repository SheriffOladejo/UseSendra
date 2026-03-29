<?php

namespace App\Models;

use PDO;

class User
{
    private $db;
    private $table = 'users';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Find user by phone number
     */
    public function findByPhone($phoneNumber)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE phone_number = :phone");
            $stmt->execute(['phone' => $phoneNumber]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error finding user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find user by Aptos address
     */
    public function findByAptosAddress($aptosAddress)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE aptos_address = :aptos_address");
            $stmt->execute(['aptos_address' => $aptosAddress]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error finding user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new user
     * Supports both sendra-native (with encrypted key) and petra users
     */
    public function create($phoneNumber, $username, $aptosAddress, $encryptedPrivateKey = null, $accountType = 'sendra', $isLogistics = false)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                    (phone_number, username, aptos_address, encrypted_private_key, account_type, wallet_connected, created_at, is_logistics)
                VALUES
                    (:phone, :username, :aptos_address, :encrypted_private_key, :account_type, 1, NOW(), :is_logistics)
            ");

            $stmt->execute([
                'phone'                 => $phoneNumber,
                'username'              => $username,
                'aptos_address'         => $aptosAddress,
                'encrypted_private_key' => $encryptedPrivateKey,
                'account_type'          => $accountType,
                'is_logistics'          => $isLogistics,
            ]);

            return $this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log("Error creating user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update user by phone number — pass any column => value pairs
     */
    public function update($phoneNumber, $data)
    {
        try {
            if (empty($data)) return false;

            $fields = [];
            $params = ['phone' => $phoneNumber];

            foreach ($data as $key => $value) {
                $fields[] = "`{$key}` = :{$key}";
                $params[$key] = $value;
            }

            $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE phone_number = :phone";
            $stmt = $this->db->prepare($sql);

            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("Error updating user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user exists by phone number
     */
    public function exists($phoneNumber)
    {
        $user = $this->findByPhone($phoneNumber);
        return $user !== false && !empty($user);
    }

    /**
     * Check if an Aptos address is already registered
     */
    public function addressExists($aptosAddress)
    {
        $user = $this->findByAptosAddress($aptosAddress);
        return $user !== false && !empty($user);
    }

    /**
     * Hash and store a 6-digit PIN for a user
     */
    public function setPin($phoneNumber, $plainPin)
    {
        $hash = password_hash($plainPin, PASSWORD_BCRYPT, ['cost' => 12]);
        return $this->update($phoneNumber, [
            'pin_hash'           => $hash,
            'awaiting_pin_setup' => 0,
        ]);
    }

    /**
     * Verify a plain PIN against the stored bcrypt hash
     */
    public function verifyPin($phoneNumber, $plainPin)
    {
        $user = $this->findByPhone($phoneNumber);
        if (!$user || empty($user['pin_hash'])) return false;
        return password_verify($plainPin, $user['pin_hash']);
    }

    /**
     * Store a pending transaction ID for the user
     */
    public function setPendingTransaction($phoneNumber, $pendingTxId)
    {
        return $this->update($phoneNumber, ['pending_tx_id' => $pendingTxId]);
    }

    /**
     * Clear pending transaction after confirm or cancel
     */
    public function clearPendingTransaction($phoneNumber)
    {
        return $this->update($phoneNumber, ['pending_tx_id' => null]);
    }
}