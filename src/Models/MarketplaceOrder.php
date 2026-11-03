<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class MarketplaceOrder
{
    private PDO $db;

    private string $table = 'marketplace_orders';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findByPaymentReference(string $ref): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE payment_reference = :ref LIMIT 1");
        $stmt->execute(['ref' => $ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findByCentiivDplId(string $dplId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE centiiv_dpl_id = :dpl LIMIT 1");
        $stmt->execute(['dpl' => $dplId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(',', $cols) . ', created_at) VALUES ('
            . implode(',', $placeholders) . ', NOW())';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);

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
}
