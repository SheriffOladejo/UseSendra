<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Services\PinConfirmTokenService;
use PDO;

/**
 * Up to five optional security questions per user.
 * Answers are hashed after normalization so verification ignores letter case.
 */
final class SecurityQuestionService
{
    public const MAX_QUESTIONS = 5;

    private bool $ready = false;

    public function __construct(private PDO $db)
    {
    }

    public function count(string $phone): int
    {
        $this->ensureTable();
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM user_security_questions WHERE phone_number = :phone'
        );
        $stmt->execute(['phone' => $phone]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{id: int, question: string, mac: string}|null
     */
    public function pickChallenge(string $phone, string $token, ?int $preferredId = null, ?string $preferredMac = null): ?array
    {
        $this->ensureTable();
        if ($preferredId !== null && $preferredId > 0 && $this->challengeMacValid($token, $preferredId, (string) $preferredMac)) {
            $row = $this->findOwned($phone, $preferredId);
            if ($row !== null) {
                return $this->present($token, $row);
            }
        }

        $stmt = $this->db->prepare(
            'SELECT id, question FROM user_security_questions WHERE phone_number = :phone ORDER BY RAND() LIMIT 1'
        );
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->present($token, $row) : null;
    }

    public function verifyChallenge(string $phone, string $token, int $id, string $mac, string $answer): bool
    {
        if ($id <= 0 || !$this->challengeMacValid($token, $id, $mac)) {
            return false;
        }

        $row = $this->findOwned($phone, $id);
        if ($row === null || empty($row['answer_hash'])) {
            return false;
        }

        $normalized = $this->normalize($answer);
        if ($normalized === '') {
            return false;
        }

        return password_verify($normalized, (string) $row['answer_hash']);
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function add(string $phone, string $question, string $answer): array
    {
        $this->ensureTable();
        $display = $this->displayQuestion($question);
        $key = $this->normalize($display);
        $answerNorm = $this->normalize($answer);

        if (mb_strlen($display) < 4 || mb_strlen($display) > 160) {
            return ['ok' => false, 'error' => 'Each security question must be between 4 and 160 characters.'];
        }
        if (mb_strlen($answerNorm) < 2 || mb_strlen($answerNorm) > 64) {
            return ['ok' => false, 'error' => 'Each security answer must be between 2 and 64 characters.'];
        }

        $hash = password_hash($answerNorm, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash === false) {
            return ['ok' => false, 'error' => 'Could not save the security question. Please try again.'];
        }

        try {
            $this->db->beginTransaction();
            $countStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM user_security_questions WHERE phone_number = :phone FOR UPDATE'
            );
            $countStmt->execute(['phone' => $phone]);
            if ((int) $countStmt->fetchColumn() >= self::MAX_QUESTIONS) {
                $this->db->rollBack();

                return ['ok' => false, 'error' => 'You can save up to 5 security questions.'];
            }

            $insert = $this->db->prepare(
                'INSERT INTO user_security_questions (phone_number, question, question_key, answer_hash)
                 VALUES (:phone, :question, :question_key, :answer_hash)'
            );
            $insert->execute([
                'phone' => $phone,
                'question' => $display,
                'question_key' => $key,
                'answer_hash' => $hash,
            ]);
            $this->db->commit();
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'error' => 'You already saved that security question.'];
            }
            error_log('Security question save failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not save the security question. Please try again.'];
        }

        return ['ok' => true];
    }

    public function challengeMac(string $token, int $id): string
    {
        return hash_hmac('sha256', $token . '|' . $id, PinConfirmTokenService::secret());
    }

    private function challengeMacValid(string $token, int $id, string $mac): bool
    {
        if ($mac === '') {
            return false;
        }

        return hash_equals($this->challengeMac($token, $id), $mac);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, question: string, mac: string}
     */
    private function present(string $token, array $row): array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'question' => (string) $row['question'],
            'mac' => $this->challengeMac($token, $id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOwned(string $phone, int $id): ?array
    {
        $this->ensureTable();
        $stmt = $this->db->prepare(
            'SELECT id, question, answer_hash FROM user_security_questions WHERE id = :id AND phone_number = :phone LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function displayQuestion(string $question): string
    {
        $question = trim(preg_replace('/\s+/u', ' ', $question) ?? '');

        return $question;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower($this->displayQuestion($value), 'UTF-8');
    }

    private function ensureTable(): void
    {
        if ($this->ready) {
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS user_security_questions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                phone_number VARCHAR(32) NOT NULL,
                question VARCHAR(200) NOT NULL,
                question_key VARCHAR(200) NOT NULL,
                answer_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_phone_question (phone_number, question_key),
                KEY idx_phone (phone_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ready = true;
    }
}
