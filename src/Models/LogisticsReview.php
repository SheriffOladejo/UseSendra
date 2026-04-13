<?php

namespace App\Models;

use PDO;

class LogisticsReview
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $row): bool
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO logistics_reviews (
                    delivery_request_id, reviewer_user_id, reviewer_driver_id,
                    reviewee_user_id, reviewee_driver_id, rating, comment
                ) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            return $stmt->execute([
                $row['delivery_request_id'],
                $row['reviewer_user_id'],
                $row['reviewer_driver_id'],
                $row['reviewee_user_id'],
                $row['reviewee_driver_id'],
                $row['rating'],
                $row['comment'] ?? null,
            ]);
        } catch (\PDOException $e) {
            error_log('LogisticsReview::create: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array{avg: ?float, count: int} */
    public function summaryForDriver(int $driverId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT ROUND(AVG(rating), 1) AS a, COUNT(*) AS c FROM logistics_reviews WHERE reviewee_driver_id = ?'
            );
            $stmt->execute([$driverId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return ['avg' => $r['a'] !== null ? (float) $r['a'] : null, 'count' => (int) $r['c']];
        } catch (\PDOException $e) {
            return ['avg' => null, 'count' => 0];
        }
    }

    /**
     * WhatsApp-friendly line: always includes a star summary (ratings or "no ratings yet").
     *
     * @param array{avg: ?float, count: int} $summary
     */
    public static function formatAverageStarsLine(string $subjectLabel, array $summary): string
    {
        $count = (int) ($summary['count'] ?? 0);
        $avg   = $summary['avg'] ?? null;
        if ($count > 0 && $avg !== null) {
            $avgDisp = round((float) $avg, 1);
            $revWord = $count === 1 ? 'review' : 'reviews';

            return "⭐ {$subjectLabel}: {$avgDisp}/5 average · {$count} {$revWord}";
        }

        return "⭐ {$subjectLabel}: no ratings yet";
    }

    /** @return array{avg: ?float, count: int} */
    public function summaryForSenderUser(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT ROUND(AVG(rating), 1) AS a, COUNT(*) AS c FROM logistics_reviews WHERE reviewee_user_id = ?'
            );
            $stmt->execute([$userId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return ['avg' => $r['a'] !== null ? (float) $r['a'] : null, 'count' => (int) $r['c']];
        } catch (\PDOException $e) {
            return ['avg' => null, 'count' => 0];
        }
    }

    /** @return list<array<string, mixed>> */
    public function listByRequestId(int $requestId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM logistics_reviews WHERE delivery_request_id = ? ORDER BY id ASC'
            );
            $stmt->execute([$requestId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function deleteByRequestId(int $requestId): void
    {
        $stmt = $this->db->prepare('DELETE FROM logistics_reviews WHERE delivery_request_id = ?');
        $stmt->execute([$requestId]);
    }
}

