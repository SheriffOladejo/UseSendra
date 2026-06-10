<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class VendorWalletTransaction
{
    private PDO $db;

    private string $table = 'vendor_wallet_transactions';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function insert(
        int $vendorId,
        string $type,
        float $amountNgn,
        float $balanceAfter,
        string $reference,
        ?int $storeOrderId = null,
        ?array $metadata = null
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table}
                (vendor_id, type, amount_ngn, balance_after_ngn, reference, store_order_id, metadata, created_at)
            VALUES (:vid, :type, :amt, :bal, :ref, :soid, :meta, NOW())
        ");
        $stmt->execute([
            'vid' => $vendorId,
            'type' => $type,
            'amt' => $amountNgn,
            'bal' => $balanceAfter,
            'ref' => $reference,
            'soid' => $storeOrderId,
            'meta' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public function referenceExists(string $reference): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$this->table} WHERE reference = :ref LIMIT 1");
        $stmt->execute(['ref' => $reference]);

        return (bool) $stmt->fetchColumn();
    }
}
