<?php

declare(strict_types=1);

namespace AptosV2\Persistence;

/**
 * pending_transactions helpers used when queueing a swap for PIN confirmation.
 */
final class PendingTransactionRepository
{
    public function __construct(private \PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $id = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare('
            INSERT INTO pending_transactions
                (id, user_phone, tx_type, amount, currency, recipient_address, human_description, expires_at, status, created_at)
            VALUES
                (:id, :user_phone, :tx_type, :amount, :currency, :recipient_address, :human_description, :expires_at, \'pending\', NOW())
        ');
        $stmt->execute(array_merge(['id' => $id], $data));

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pending_transactions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Active pending row only (status = pending).
     *
     * @return array<string, mixed>|null
     */
    public function findActivePending(string $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pending_transactions WHERE id = :id AND status = 'pending' LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeMeta(array $row): array
    {
        $meta = json_decode((string) ($row['human_description'] ?? ''), true);

        return is_array($meta) ? $meta : [];
    }

    public function isV2Pending(array $row): bool
    {
        return !empty($this->decodeMeta($row)['aptos_v2']);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isAlreadyClaimedOrDone(array $row): bool
    {
        $st = (string) ($row['status'] ?? '');
        if (in_array($st, ['confirmed', 'failed', 'cancelled', 'expired'], true)) {
            return true;
        }

        return !empty($this->decodeMeta($row)['pin_claimed']);
    }

    /**
     * Lock pending row so duplicate PIN deliveries only execute once.
     */
    public function claimForExecution(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                'SELECT id, status, human_description FROM pending_transactions WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (string) ($row['status'] ?? '') !== 'pending') {
                $this->db->rollBack();

                return false;
            }

            $meta = $this->decodeMeta($row);
            if (!empty($meta['pin_claimed'])) {
                $this->db->rollBack();

                return false;
            }

            $meta['pin_claimed'] = true;
            $meta['pin_claimed_at'] = gmdate('c');
            $u = $this->db->prepare(
                'UPDATE pending_transactions SET human_description = ? WHERE id = ? AND status = ?'
            );
            $u->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $id, 'pending']);
            $this->db->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('PendingTransactionRepository::claimForExecution: ' . $e->getMessage());

            return false;
        }
    }

    public function cancel(string $id): void
    {
        if ($id === '') {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE pending_transactions SET status = 'cancelled' WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function mergeMeta(string $id, array $patch): void
    {
        if ($id === '') {
            return;
        }
        $row = $this->findById($id);
        if ($row === null) {
            return;
        }
        $meta = array_merge($this->decodeMeta($row), $patch);
        $stmt = $this->db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
        $stmt->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
    }

    public function updateStatus(string $id, string $status, ?string $txHash = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pending_transactions SET status = :status, tx_hash = :tx_hash WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'tx_hash' => $txHash, 'id' => $id]);
    }

    public function updateRecipient(string $id, string $recipient): void
    {
        if ($id === '') {
            return;
        }
        $stmt = $this->db->prepare('UPDATE pending_transactions SET recipient_address = ? WHERE id = ?');
        $stmt->execute([$recipient, $id]);
    }

    public function extendExpiry(string $id, int $seconds): void
    {
        if ($id === '' || $seconds <= 0) {
            return;
        }
        try {
            $stmt = $this->db->prepare('UPDATE pending_transactions SET expires_at = :exp WHERE id = :id');
            $stmt->execute(['exp' => date('Y-m-d H:i:s', time() + $seconds), 'id' => $id]);
        } catch (\Throwable $e) {
            error_log('PendingTransactionRepository::extendExpiry: ' . $e->getMessage());
        }
    }
}
