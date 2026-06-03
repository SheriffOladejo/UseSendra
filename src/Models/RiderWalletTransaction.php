<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class RiderWalletTransaction
{
    private string $table = 'rider_wallet_transactions';

    public function __construct(private PDO $db)
    {
    }

    public function findByReference(string $reference): ?array
    {
        if ($reference === '') {
            return null;
        }
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE reference = ? LIMIT 1");
            $stmt->execute([$reference]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\PDOException $e) {
            error_log('RiderWalletTransaction::findByReference: ' . $e->getMessage());

            return null;
        }
    }

    public function createPending(array $row): ?int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                    (driver_id, type, amount_ngn, balance_after_ngn, reference, status,
                     delivery_request_id, centiiv_resource_id, metadata)
                VALUES
                    (:driver_id, :type, :amount_ngn, :balance_after_ngn, :reference, 'pending',
                     :delivery_request_id, :centiiv_resource_id, :metadata)
            ");
            $meta = $row['metadata'] ?? null;
            $stmt->execute([
                'driver_id' => (int) $row['driver_id'],
                'type' => (string) $row['type'],
                'amount_ngn' => $row['amount_ngn'],
                'balance_after_ngn' => $row['balance_after_ngn'] ?? 0,
                'reference' => (string) $row['reference'],
                'delivery_request_id' => $row['delivery_request_id'] ?? null,
                'centiiv_resource_id' => $row['centiiv_resource_id'] ?? null,
                'metadata' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log('RiderWalletTransaction::createPending: ' . $e->getMessage());

            return null;
        }
    }

    public function markCompleted(string $reference, float $balanceAfter, ?string $centiivResourceId = null): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET status = 'completed',
                    balance_after_ngn = :bal,
                    completed_at = NOW(),
                    centiiv_resource_id = COALESCE(:cid, centiiv_resource_id)
                WHERE reference = :ref AND status = 'pending'
            ");

            return $stmt->execute([
                'bal' => $balanceAfter,
                'cid' => $centiivResourceId,
                'ref' => $reference,
            ]);
        } catch (\PDOException $e) {
            error_log('RiderWalletTransaction::markCompleted: ' . $e->getMessage());

            return false;
        }
    }

    public function markFailed(string $reference): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE {$this->table}
                SET status = 'failed', completed_at = NOW()
                WHERE reference = :ref AND status = 'pending'
            ");

            return $stmt->execute(['ref' => $reference]);
        } catch (\PDOException $e) {
            error_log('RiderWalletTransaction::markFailed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecentForDriver(int $driverId, int $limit = 20): array
    {
        if ($driverId < 1 || $limit < 1) {
            return [];
        }
        try {
            $stmt = $this->db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE driver_id = ?
                ORDER BY COALESCE(completed_at, created_at) DESC
                LIMIT " . (int) $limit . "
            ");
            $stmt->execute([$driverId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('RiderWalletTransaction::listRecentForDriver: ' . $e->getMessage());

            return [];
        }
    }
}
