<?php

declare(strict_types=1);

namespace AptosV2\Persistence;

/**
 * On-ramp (buy) pending rows — Centiiv virtual-bank orders.
 */
final class OnrampPendingRepository
{
    public function __construct(private \PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function store(array $data): string
    {
        $id = bin2hex(random_bytes(16));
        unset($data['expires_at']);
        $stmt = $this->db->prepare('
            INSERT INTO pending_transactions
                (id, user_phone, tx_type, amount, currency, recipient_address, human_description, expires_at, status, created_at)
            VALUES
                (:id, :user_phone, :tx_type, :amount, :currency, :recipient_address, :human_description, DATE_ADD(NOW(), INTERVAL 30 MINUTE), \'pending\', NOW())
        ');
        $stmt->execute(array_merge(['id' => $id], $data));

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestOpenForPhone(string $phone): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_phone, human_description, status, created_at
            FROM pending_transactions
            WHERE user_phone = :phone
              AND tx_type IN ('onramp_oneclick_centiiv', 'onramp_daya_aptos')
              AND status IN ('pending', 'confirmed')
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCentiivOrderId(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT id FROM pending_transactions
            WHERE tx_type IN ('onramp_oneclick_centiiv', 'onramp_daya_aptos')
              AND (
                human_description LIKE :like_id
                OR human_description LIKE :like_id2
              )
            LIMIT 1
        ");
        $stmt->execute([
            'like_id' => '%"centiiv_order_id":"' . $orderId . '"%',
            'like_id2' => '%"centiiv_order_id": "' . $orderId . '"%',
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
