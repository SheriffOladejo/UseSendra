<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Durable queue for async buy quotes (Centiiv + optional 1Click dry).
 */
final class SendraBuyQuoteJobService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function isAvailable(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM buy_quote_jobs LIMIT 1');

            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * @return array{success: bool, job_id: ?string, error: ?string, already_active: bool}
     */
    public function enqueue(
        string $userPhone,
        string $quoteMode,
        string $asset,
        string $aptosAddress,
        ?float $payNgn = null,
        ?float $targetCrypto = null,
        ?string $triggerWamid = null
    ): array {
        if (!$this->isAvailable()) {
            return ['success' => false, 'job_id' => null, 'error' => 'jobs_table_missing', 'already_active' => false];
        }

        $asset = strtoupper(trim($asset));
        if (!in_array($asset, ['USDC', 'USDT'], true)) {
            return ['success' => false, 'job_id' => null, 'error' => 'unsupported_asset', 'already_active' => false];
        }
        $quoteMode = $quoteMode === 'crypto_target' ? 'crypto_target' : 'naira';
        if ($quoteMode === 'naira' && ($payNgn === null || $payNgn < 100)) {
            return ['success' => false, 'job_id' => null, 'error' => 'invalid_pay_ngn', 'already_active' => false];
        }
        if ($quoteMode === 'crypto_target' && ($targetCrypto === null || $targetCrypto < 0.01)) {
            return ['success' => false, 'job_id' => null, 'error' => 'invalid_target_crypto', 'already_active' => false];
        }
        if ($aptosAddress === '' || !str_starts_with(strtolower($aptosAddress), '0x')) {
            return ['success' => false, 'job_id' => null, 'error' => 'missing_aptos', 'already_active' => false];
        }

        $active = $this->findActiveJobForPhone($userPhone);
        if ($active !== null) {
            return [
                'success' => false,
                'job_id' => (string) $active['id'],
                'error' => 'quote_already_active',
                'already_active' => true,
            ];
        }

        $id = bin2hex(random_bytes(16));
        $ttlMinutes = max(2, (int) ($_ENV['SENDRA_BUY_QUOTE_JOB_TTL_MINUTES'] ?? getenv('SENDRA_BUY_QUOTE_JOB_TTL_MINUTES') ?: 3));
        $wamid = $triggerWamid !== null ? trim($triggerWamid) : null;
        if ($wamid === '') {
            $wamid = null;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO buy_quote_jobs (
                    id, user_phone, quote_mode, pay_ngn, target_crypto, asset, aptos_address,
                    status, trigger_wamid, expires_at
                ) VALUES (
                    :id, :phone, :mode, :pay_ngn, :target_crypto, :asset, :aptos,
                    'pending', :wamid, DATE_ADD(NOW(), INTERVAL :ttl MINUTE)
                )
            ");
            $stmt->execute([
                'id' => $id,
                'phone' => $userPhone,
                'mode' => $quoteMode,
                'pay_ngn' => $quoteMode === 'naira' ? $payNgn : null,
                'target_crypto' => $quoteMode === 'crypto_target' ? $targetCrypto : null,
                'asset' => $asset,
                'aptos' => $aptosAddress,
                'wamid' => $wamid,
                'ttl' => $ttlMinutes,
            ]);
        } catch (\PDOException $e) {
            if ($wamid !== null && self::isDuplicateKey($e)) {
                return ['success' => false, 'job_id' => null, 'error' => 'duplicate_wamid', 'already_active' => true];
            }
            error_log('SendraBuyQuoteJob enqueue: ' . $e->getMessage());

            return ['success' => false, 'job_id' => null, 'error' => 'enqueue_failed', 'already_active' => false];
        }

        return ['success' => true, 'job_id' => $id, 'error' => null, 'already_active' => false];
    }

    public function cancelActiveForPhone(string $userPhone): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $stmt = $this->db->prepare("
            UPDATE buy_quote_jobs
            SET status = 'cancelled', finished_at = NOW(), error_code = 'user_cancelled'
            WHERE user_phone = :phone AND status IN ('pending', 'processing')
        ");
        $stmt->execute(['phone' => $userPhone]);

        return $stmt->rowCount() > 0;
    }

    public function cancelJob(string $jobId, string $reason = 'cancelled'): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $stmt = $this->db->prepare("
            UPDATE buy_quote_jobs
            SET status = 'cancelled', finished_at = NOW(), error_code = :code
            WHERE id = :id AND status IN ('pending', 'processing')
        ");
        $stmt->execute(['id' => $jobId, 'code' => $reason]);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findActiveJobForPhone(string $userPhone): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT * FROM buy_quote_jobs
            WHERE user_phone = :phone AND status IN ('pending', 'processing')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['phone' => $userPhone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findById(string $jobId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM buy_quote_jobs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimPendingJobs(int $limit = 10): array
    {
        if (!$this->isAvailable() || $limit < 1) {
            return [];
        }

        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare("
                SELECT id FROM buy_quote_jobs
                WHERE status = 'pending' AND expires_at > NOW()
                ORDER BY created_at ASC
                LIMIT :lim
                FOR UPDATE
            ");
            $sel->bindValue('lim', $limit, PDO::PARAM_INT);
            $sel->execute();
            $ids = [];
            while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row) && isset($row['id'])) {
                    $ids[] = (string) $row['id'];
                }
            }
            if ($ids === []) {
                $this->db->commit();

                return [];
            }

            $claimed = [];
            $upd = $this->db->prepare("
                UPDATE buy_quote_jobs
                SET status = 'processing', claimed_at = NOW(), attempt_count = attempt_count + 1
                WHERE id = :id AND status = 'pending'
            ");
            $fetch = $this->db->prepare('SELECT * FROM buy_quote_jobs WHERE id = :id LIMIT 1');
            foreach ($ids as $id) {
                $upd->execute(['id' => $id]);
                if ($upd->rowCount() < 1) {
                    continue;
                }
                $fetch->execute(['id' => $id]);
                $row = $fetch->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $claimed[] = $row;
                }
            }
            $this->db->commit();

            return $claimed;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('SendraBuyQuoteJob claimPendingJobs: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findLatestDoneJobForPhone(string $userPhone): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM buy_quote_jobs WHERE user_phone = :phone AND status = 'done'
             ORDER BY COALESCE(finished_at, created_at) DESC, created_at DESC LIMIT 1"
        );
        $stmt->execute(['phone' => $userPhone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function markProgressSent(string $jobId): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE buy_quote_jobs SET progress_sent = 1 WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
    }

    public function completeJob(string $jobId, array $resultJson): void
    {
        $stmt = $this->db->prepare("
            UPDATE buy_quote_jobs
            SET status = 'done', finished_at = NOW(), result_json = :json, error_code = NULL
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $jobId,
            'json' => json_encode($resultJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function failJob(string $jobId, string $errorCode, ?array $resultJson = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE buy_quote_jobs
            SET status = 'failed', finished_at = NOW(), error_code = :code, result_json = :json
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $jobId,
            'code' => $errorCode,
            'json' => $resultJson !== null
                ? json_encode($resultJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ]);
    }

    /**
     * @param array<string, mixed> $quoteResult from quoteNairaBuy / quoteCryptoBuy
     * @param array<string, mixed> $jobRow
     */
    public static function buildResultJsonV1(array $quoteResult, array $jobRow, int $durationMs): array
    {
        $mode = (string) ($jobRow['quote_mode'] ?? 'naira');
        $asset = strtoupper((string) ($jobRow['asset'] ?? 'USDC'));
        $payNgn = (float) ($quoteResult['pay_ngn'] ?? $jobRow['pay_ngn'] ?? 0);
        $centiivUsdc = isset($quoteResult['centiiv_usdc']) ? (float) $quoteResult['centiiv_usdc'] : null;
        $bridgeOn = !empty($quoteResult['oneclick_dry_quote_data']);
        $dryRaw = is_array($quoteResult['oneclick_dry_quote_data'] ?? null)
            ? $quoteResult['oneclick_dry_quote_data']
            : null;

        $oneclick = null;
        $validation = [
            'centiiv_usdc_smallest' => null,
            'min_stellar_required_smallest' => null,
            'centiiv_covers_min_in_preview' => !$bridgeOn,
            'order_time_requote_required' => $bridgeOn,
            'max_centiiv_usdc_drop_bps' => (int) ($_ENV['SENDRA_BUY_CENTIIV_REFRESH_MAX_DROP_BPS'] ?? getenv('SENDRA_BUY_CENTIIV_REFRESH_MAX_DROP_BPS') ?: 100),
        ];

        if ($bridgeOn && $dryRaw !== null) {
            $parsed = SendraOneClickService::parseQuoteResponse($dryRaw);
            $originDecimals = SendraOfframpBridge::destDecimals();
            $haircutBps = (int) ($_ENV['SENDRA_BUY_ONECLICK_STELLAR_HAIRCUT_BPS'] ?? getenv('SENDRA_BUY_ONECLICK_STELLAR_HAIRCUT_BPS') ?: 200);
            $centiivSmallest = $centiivUsdc !== null
                ? SendraOneClickService::cryptoAmountToSmallestUnits(
                    rtrim(rtrim(number_format($centiivUsdc, 6, '.', ''), '0'), '.'),
                    $originDecimals
                )
                : null;
            $minIn = $parsed['min_amount_in'] ?? null;
            $covers = false;
            if ($centiivSmallest !== null && $minIn !== null && ctype_digit($centiivSmallest) && ctype_digit($minIn)) {
                $covers = bccomp($centiivSmallest, $minIn, 0) >= 0;
            }
            $validation['centiiv_usdc_smallest'] = $centiivSmallest;
            $validation['min_stellar_required_smallest'] = $minIn;
            $validation['centiiv_covers_min_in_preview'] = $covers;
            if ($centiivSmallest !== null && $minIn !== null && ctype_digit($centiivSmallest) && ctype_digit($minIn)) {
                $validation['preview_safety_margin_smallest'] = bcsub($centiivSmallest, $minIn, 0);
            }

            $oneclick = [
                'enabled' => true,
                'swap_type' => strtoupper(trim((string) ($_ENV['SENDRA_BUY_ONECLICK_SWAP_TYPE'] ?? getenv('SENDRA_BUY_ONECLICK_SWAP_TYPE') ?: 'FLEX_INPUT'))),
                'haircut_bps' => $haircutBps,
                'dry' => [
                    'min_amount_in_smallest' => $parsed['min_amount_in'],
                    'min_amount_in_display' => self::formatSmallest($parsed['min_amount_in'], $originDecimals),
                    'min_amount_out_display' => $parsed['min_amount_out_formatted']
                        ?? self::formatSmallest($parsed['min_amount_out'], (int) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_DECIMALS'] ?? 6)),
                    'amount_out_display' => $parsed['amount_out_formatted'],
                ],
            ];
        }

        return [
            'schema_version' => 1,
            'quoted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'quote_mode' => $mode,
            'duration_ms' => $durationMs,
            'inputs' => [
                'quote_mode' => $mode,
                'pay_ngn' => $payNgn,
                'target_crypto' => isset($jobRow['target_crypto']) ? (float) $jobRow['target_crypto'] : null,
                'asset' => $asset,
                'aptos_address' => (string) ($jobRow['aptos_address'] ?? ''),
                'user_phone' => (string) ($jobRow['user_phone'] ?? ''),
            ],
            'display' => [
                'receive_crypto_display' => (string) ($quoteResult['receive_crypto_display'] ?? '—'),
                'pay_ngn_display' => number_format($payNgn, 0, '.', ','),
                'rate_hint' => $quoteResult['rate_hint'] ?? null,
                'fee_hint' => $quoteResult['fee_hint'] ?? null,
                'bridge_note_shown' => $bridgeOn,
                'target_crypto_display' => $mode === 'crypto_target' && isset($jobRow['target_crypto'])
                    ? rtrim(rtrim(number_format((float) $jobRow['target_crypto'], 6, '.', ''), '0'), '.')
                    : null,
            ],
            'centiiv' => [
                'usdc_estimate' => $centiivUsdc,
            ],
            'bridge' => ['enabled' => $bridgeOn],
            'oneclick' => $oneclick,
            'validation' => $validation,
            'raw' => [
                'oneclick_dry_quote_response' => $dryRaw,
            ],
            'legacy_quote' => [
                'pay_ngn' => $payNgn,
                'asset' => $asset,
                'receive_crypto_display' => (string) ($quoteResult['receive_crypto_display'] ?? '—'),
                'centiiv_usdc' => $centiivUsdc,
                'oneclick_dry_quote_data' => $dryRaw,
                'rate_hint' => $quoteResult['rate_hint'] ?? null,
                'fee_hint' => $quoteResult['fee_hint'] ?? null,
            ],
        ];
    }

    public function expireStalePending(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $stmt = $this->db->prepare("
            UPDATE buy_quote_jobs
            SET status = 'failed', finished_at = NOW(), error_code = 'expired'
            WHERE status = 'pending' AND expires_at <= NOW()
        ");
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Workers can die mid-quote (PHP fatal, timeout). Re-queue or fail stuck processing jobs.
     */
    public function recoverStaleProcessing(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $staleMinutes = max(2, (int) ($_ENV['SENDRA_BUY_QUOTE_PROCESSING_STALE_MINUTES'] ?? getenv('SENDRA_BUY_QUOTE_PROCESSING_STALE_MINUTES') ?: 3));

        $fail = $this->db->prepare("
            UPDATE buy_quote_jobs
            SET status = 'failed', finished_at = NOW(), error_code = 'processing_stale'
            WHERE status = 'processing'
              AND claimed_at IS NOT NULL
              AND claimed_at < DATE_SUB(NOW(), INTERVAL :mins MINUTE)
              AND attempt_count >= max_attempts
        ");
        $fail->bindValue('mins', $staleMinutes, PDO::PARAM_INT);
        $fail->execute();
        $failed = $fail->rowCount();

        $requeue = $this->db->prepare("
            UPDATE buy_quote_jobs
            SET status = 'pending', claimed_at = NULL, error_code = 'processing_stale_requeued'
            WHERE status = 'processing'
              AND claimed_at IS NOT NULL
              AND claimed_at < DATE_SUB(NOW(), INTERVAL :mins MINUTE)
              AND attempt_count < max_attempts
              AND expires_at > NOW()
        ");
        $requeue->bindValue('mins', $staleMinutes, PDO::PARAM_INT);
        $requeue->execute();
        $requeued = $requeue->rowCount();

        // Expired while stuck in processing — fail so the user gets a prompt to retry.
        $failExpired = $this->db->prepare("
            UPDATE buy_quote_jobs
            SET status = 'failed', finished_at = NOW(), error_code = 'processing_stale'
            WHERE status = 'processing'
              AND claimed_at IS NOT NULL
              AND claimed_at < DATE_SUB(NOW(), INTERVAL :mins MINUTE)
              AND attempt_count < max_attempts
              AND expires_at <= NOW()
        ");
        $failExpired->bindValue('mins', $staleMinutes, PDO::PARAM_INT);
        $failExpired->execute();

        return $failed + $requeued + $failExpired->rowCount();
    }

    private static function formatSmallest(?string $smallest, int $decimals): ?string
    {
        if ($smallest === null || $smallest === '' || !ctype_digit($smallest)) {
            return null;
        }
        if (function_exists('bcdiv')) {
            $scale = (string) (10 ** max(0, min(18, $decimals)));

            return rtrim(rtrim(bcdiv($smallest, $scale, $decimals), '0'), '.') ?: '0';
        }

        return (string) ((float) $smallest / (10 ** $decimals));
    }

    private static function isDuplicateKey(\PDOException $e): bool
    {
        $info = $e->errorInfo ?? null;

        return is_array($info) && isset($info[1]) && (int) $info[1] === 1062;
    }
}
