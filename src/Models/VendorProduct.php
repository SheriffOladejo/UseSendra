<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class VendorProduct
{
    private PDO $db;

    private string $table = 'vendor_products';

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
    public function listByVendor(int $vendorId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE vendor_id = :vid";
        if ($activeOnly) {
            $sql .= " AND status = 'active' AND stock_status = 'in_stock'";
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['vid' => $vendorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function create(int $vendorId, string $name, float $priceNgn, ?string $description = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (vendor_id, name, description, price_ngn, created_at)
            VALUES (:vid, :name, :desc, :price, NOW())
        ");
        $stmt->execute([
            'vid' => $vendorId,
            'name' => $name,
            'desc' => $description,
            'price' => $priceNgn,
        ]);

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
