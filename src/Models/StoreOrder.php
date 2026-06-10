<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class StoreOrder
{
    private PDO $db;

    private string $table = 'store_orders';

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

    /**
     * @return list<array<string, mixed>>
     */
    public function listByMarketplaceOrder(int $marketplaceOrderId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE marketplace_order_id = :mid ORDER BY id");
        $stmt->execute(['mid' => $marketplaceOrderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAwaitingVendorPastDeadline(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM {$this->table}
            WHERE status = 'awaiting_vendor'
              AND vendor_accept_deadline IS NOT NULL
              AND vendor_accept_deadline < NOW()
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
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

    public function findByDeliveryRequestId(int $deliveryRequestId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE delivery_request_id = :dr LIMIT 1");
        $stmt->execute(['dr' => $deliveryRequestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findByReturnDeliveryRequestId(int $deliveryRequestId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE return_delivery_request_id = :dr LIMIT 1");
        $stmt->execute(['dr' => $deliveryRequestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function countActiveByVendor(int $vendorId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE vendor_id = :vid
              AND status IN ('awaiting_vendor','accepted','preparing','ready','rider_assigned','picked_up')
        ");
        $stmt->execute(['vid' => $vendorId]);

        return (int) $stmt->fetchColumn();
    }
}
