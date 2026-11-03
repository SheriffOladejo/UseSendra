<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Services\WhatsAppService;
use PDO;

/**
 * Credit pings WhatsApp refuses outside the 24-hour window (error 131047).
 * They are stored and sent as a normal message the next time that person replies.
 */
final class PendingWhatsAppNoticeService
{
    private bool $ready = false;

    public function __construct(private PDO $db)
    {
    }

    public function queue(string $phone, string $body): int
    {
        $this->ensureTable();
        $stmt = $this->db->prepare(
            'INSERT INTO pending_wa_notices (phone_number, body, status) VALUES (:phone, :body, \'pending\')'
        );
        $stmt->execute(['phone' => $phone, 'body' => $body]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed>|null $result
     */
    public function recordAttempt(int $id, ?array $result): void
    {
        if ($id <= 0) {
            return;
        }

        $this->ensureTable();
        $wamid = '';
        if (is_array($result)) {
            $message = $result['response']['messages'][0]['id'] ?? '';
            $wamid = is_string($message) ? $message : '';
        }
        $accepted = is_array($result) && !empty($result['success']) && $wamid !== '';
        $stmt = $this->db->prepare(
            'UPDATE pending_wa_notices SET wamid = :wamid, status = :status WHERE id = :id'
        );
        $stmt->execute([
            'wamid' => $wamid !== '' ? $wamid : null,
            'status' => $accepted ? 'sent' : 'failed',
            'id' => $id,
        ]);
    }

    public function markFromStatus(string $wamid, string $status): void
    {
        $wamid = trim($wamid);
        if ($wamid === '') {
            return;
        }

        $this->ensureTable();
        $next = match ($status) {
            'delivered', 'read' => 'delivered',
            'failed' => 'failed',
            default => null,
        };
        if ($next === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE pending_wa_notices SET status = :status WHERE wamid = :wamid AND status <> \'delivered\''
        );
        $stmt->execute(['status' => $next, 'wamid' => $wamid]);
    }

    public function flush(string $phone, WhatsAppService $whatsapp): void
    {
        $this->ensureTable();
        $stmt = $this->db->prepare(
            'SELECT id, body FROM pending_wa_notices WHERE phone_number = :phone AND status = \'failed\' ORDER BY id ASC'
        );
        $stmt->execute(['phone' => $phone]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $body = (string) ($row['body'] ?? '');
            if ($id <= 0 || $body === '') {
                continue;
            }
            try {
                $result = $whatsapp->sendMessage($phone, $body);
                $this->recordAttempt($id, is_array($result) ? $result : null);
            } catch (\Throwable $e) {
                error_log('PendingWhatsAppNotice flush failed: ' . $e->getMessage());
            }
        }
    }

    private function ensureTable(): void
    {
        if ($this->ready) {
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS pending_wa_notices (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                phone_number VARCHAR(32) NOT NULL,
                body TEXT NOT NULL,
                wamid VARCHAR(200) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_phone_status (phone_number, status),
                KEY idx_wamid (wamid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ready = true;
    }
}
