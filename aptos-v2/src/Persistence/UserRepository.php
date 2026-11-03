<?php

declare(strict_types=1);

namespace AptosV2\Persistence;

/**
 * Reads user rows from the Aptos database.
 *
 * v2 always resolves the user itself (against the Aptos DB) rather than trusting
 * the row passed in by webhook.php — because during testing the inbound webhook
 * may be bound to the Logistics phone number / DB. See README "Testing setup".
 */
final class UserRepository
{
    public function __construct(private \PDO $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE phone_number = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function clearPendingTransaction(string $phone): void
    {
        $stmt = $this->db->prepare('UPDATE users SET pending_tx_id = NULL WHERE phone_number = :phone');
        $stmt->execute(['phone' => $phone]);
    }

    public function exists(string $phone): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE phone_number = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $phone, array $data): void
    {
        if ($data === []) {
            return;
        }

        $sets = [];
        $params = ['phone' => $phone];
        foreach ($data as $key => $value) {
            $sets[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE phone_number = :phone';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
