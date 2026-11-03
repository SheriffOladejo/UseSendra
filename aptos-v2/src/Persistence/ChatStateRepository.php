<?php

declare(strict_types=1);

namespace AptosV2\Persistence;

/**
 * Persists per-user flow state in `users.chat_context` (JSON).
 *
 * This is the same column the legacy controller uses for buy/sell/swap context,
 * so v2 and legacy never fight over a parallel store. The shape is:
 *
 *   { "flow": "swap", "step": "confirm", ...arbitrary flow data... }
 */
final class ChatStateRepository
{
    public function __construct(private \PDO $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $phone): array
    {
        $stmt = $this->db->prepare('SELECT chat_context FROM users WHERE phone_number = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $raw = $stmt->fetchColumn();

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(string $phone, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $stmt = $this->db->prepare('UPDATE users SET chat_context = :ctx WHERE phone_number = :phone');
        $stmt->execute(['ctx' => $json, 'phone' => $phone]);
    }

    public function clear(string $phone): void
    {
        $stmt = $this->db->prepare('UPDATE users SET chat_context = NULL WHERE phone_number = :phone');
        $stmt->execute(['phone' => $phone]);
    }
}
