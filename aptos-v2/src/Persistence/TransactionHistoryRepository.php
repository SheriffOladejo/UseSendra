<?php

declare(strict_types=1);

namespace AptosV2\Persistence;

use AptosV2\Support\ChainNetwork;

/**
 * Recent transaction history for the /history command.
 */
final class TransactionHistoryRepository
{
    public function __construct(private \PDO $db)
    {
    }

    public function pdo(): \PDO
    {
        return $this->db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentForPhone(string $phone, int $limit = 5): array
    {
        $stmt = $this->db->prepare('
            SELECT direction, amount, currency, chain, counterparty, tx_hash, explorer_url, created_at
            FROM transaction_history
            WHERE user_phone = ?
            ORDER BY created_at DESC
            LIMIT ' . (int) $limit . '
        ');
        $stmt->execute([$phone]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): void
    {
        try {
            $explorer = (string) ($data['explorer_url'] ?? '');
            $chain = ChainNetwork::resolve(
                isset($data['chain']) ? (string) $data['chain'] : null,
                $explorer
            );
            $stmt = $this->db->prepare('
                INSERT INTO transaction_history
                    (id, user_phone, direction, amount, currency, chain, status,
                     aptos_address, counterparty, tx_hash, explorer_url, created_at)
                VALUES
                    (:id, :user_phone, :direction, :amount, :currency, :chain, :status,
                     :aptos_address, :counterparty, :tx_hash, :explorer_url, NOW())
                ON DUPLICATE KEY UPDATE id = id
            ');
            $stmt->execute([
                'id' => bin2hex(random_bytes(16)),
                'user_phone' => (string) ($data['user_phone'] ?? ''),
                'direction' => (string) ($data['direction'] ?? 'out'),
                'amount' => $data['amount'] ?? 0,
                'currency' => (string) ($data['currency'] ?? 'USDC'),
                'chain' => $chain,
                'status' => (string) ($data['status'] ?? 'confirmed'),
                'aptos_address' => (string) ($data['aptos_address'] ?? ''),
                'counterparty' => (string) ($data['counterparty'] ?? ''),
                'tx_hash' => (string) ($data['tx_hash'] ?? ''),
                'explorer_url' => $explorer,
            ]);
        } catch (\PDOException $e) {
            error_log('TransactionHistoryRepository::record: ' . $e->getMessage());
        }
    }
}
