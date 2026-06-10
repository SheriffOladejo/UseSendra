<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class StoreOrderItem
{
    private PDO $db;

    private string $table = 'store_order_items';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByStoreOrder(int $storeOrderId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE store_order_id = :sid");
        $stmt->execute(['sid' => $storeOrderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }
}
