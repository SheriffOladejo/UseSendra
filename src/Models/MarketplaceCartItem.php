<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class MarketplaceCartItem
{
    private PDO $db;

    private string $table = 'marketplace_cart_items';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByBuyer(string $buyerPhone): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, v.store_name, v.status AS vendor_status, v.pickup_lat, v.pickup_lng
            FROM {$this->table} c
            JOIN vendors v ON v.id = c.vendor_id
            WHERE c.buyer_phone = :phone
            ORDER BY v.store_name, c.product_name
        ");
        $stmt->execute(['phone' => $buyerPhone]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function addOrUpdate(
        string $buyerPhone,
        int $vendorId,
        int $productId,
        string $productName,
        float $unitPrice,
        int $quantity
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table}
                (buyer_phone, vendor_id, product_id, quantity, unit_price_ngn, product_name)
            VALUES (:phone, :vid, :pid, :qty, :price, :pname)
            ON DUPLICATE KEY UPDATE
                quantity = :qty2,
                unit_price_ngn = :price2,
                product_name = :pname2,
                vendor_id = :vid2
        ");
        $stmt->execute([
            'phone' => $buyerPhone,
            'vid' => $vendorId,
            'pid' => $productId,
            'qty' => $quantity,
            'price' => $unitPrice,
            'pname' => $productName,
            'qty2' => $quantity,
            'price2' => $unitPrice,
            'pname2' => $productName,
            'vid2' => $vendorId,
        ]);
    }

    public function remove(int $cartItemId, string $buyerPhone): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id AND buyer_phone = :phone");

        return $stmt->execute(['id' => $cartItemId, 'phone' => $buyerPhone]);
    }

    public function clear(string $buyerPhone): void
    {
        $this->db->prepare("DELETE FROM {$this->table} WHERE buyer_phone = ?")->execute([$buyerPhone]);
    }
}
