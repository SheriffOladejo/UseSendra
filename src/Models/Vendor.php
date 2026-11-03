<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Vendor
{
    private PDO $db;

    private string $table = 'vendors';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE phone = :phone LIMIT 1");
        $stmt->execute(['phone' => $phone]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(string $phone, string $storeName): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (phone, store_name, status, created_at)
            VALUES (:phone, :store_name, 'pending', NOW())
        ");
        $stmt->execute(['phone' => $phone, 'store_name' => $storeName]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $fields = [];
        $params = ['id' => $id];
        foreach ($data as $key => $value) {
            $fields[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }
        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBrowsable(int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT id, store_name, pickup_lat, pickup_lng
            FROM {$this->table}
            WHERE status = 'active'
            ORDER BY store_name ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function incrementCancelMetric(int $vendorId): void
    {
        $this->db->prepare("
            UPDATE {$this->table}
            SET cancel_count_30d = cancel_count_30d + 1,
                order_count_30d = order_count_30d + 1
            WHERE id = ?
        ")->execute([$vendorId]);
    }

    public function incrementOrderMetric(int $vendorId): void
    {
        $this->db->prepare("
            UPDATE {$this->table}
            SET order_count_30d = order_count_30d + 1
            WHERE id = ?
        ")->execute([$vendorId]);
    }

    public function adjustWallet(int $vendorId, float $deltaNgn): float
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT wallet_balance_ngn FROM {$this->table} WHERE id = ? FOR UPDATE");
            $stmt->execute([$vendorId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $bal = (float) ($row['wallet_balance_ngn'] ?? 0);
            $newBal = $bal + $deltaNgn;
            $this->db->prepare("UPDATE {$this->table} SET wallet_balance_ngn = ? WHERE id = ?")
                ->execute([$newBal, $vendorId]);
            $this->db->commit();

            return $newBal;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
