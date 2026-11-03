<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Records completed off-ramp outcomes and derives P90 buffers so bank payout ≥ target NGN.
 */
final class SendraOfframpSlippageService
{
    private const CACHE_FILE = 'sendra_offramp_learned.json';

    /**
     * @param array{
     *   centiiv_order_id: string,
     *   user_phone?: ?string,
     *   offramp_path?: string,
     *   target_ngn: float,
     *   quoted_ngn_at_pin?: ?float,
     *   fulfillment_ngn: float,
     *   usdt_debit?: ?float,
     *   panora_wallet_usdc?: ?float,
     *   stellar_deposit_usdc?: ?float,
     *   centiiv_order_usdc?: ?float
     * } $row
     */
    public static function recordOutcome(array $row): bool
    {
        $orderId = trim((string) ($row['centiiv_order_id'] ?? ''));
        if ($orderId === '') {
            return false;
        }

        $target = (float) ($row['target_ngn'] ?? 0);
        $fulfillment = (float) ($row['fulfillment_ngn'] ?? 0);
        if ($target <= 0 || $fulfillment <= 0) {
            return false;
        }

        $panora = isset($row['panora_wallet_usdc']) ? (float) $row['panora_wallet_usdc'] : null;
        $stellar = isset($row['stellar_deposit_usdc']) ? (float) $row['stellar_deposit_usdc'] : null;
        $haircutRatio = null;
        if ($panora !== null && $panora > 1e-9 && $stellar !== null && $stellar > 0) {
            $haircutRatio = max(0.0, min(0.25, 1.0 - ($stellar / $panora)));
        }

        $shortfall = max(0.0, $target - $fulfillment);

        try {
            $db = self::aptosDb();
            $stmt = $db->prepare(
                'INSERT INTO sendra_offramp_outcomes (
                    centiiv_order_id, user_phone, offramp_path,
                    target_ngn, quoted_ngn_at_pin, fulfillment_ngn,
                    usdt_debit, panora_wallet_usdc, stellar_deposit_usdc, centiiv_order_usdc,
                    haircut_ratio, ngn_shortfall,
                    oneclick_deposit_address, oneclick_swap_id, aptos_tx_hash
                ) VALUES (
                    :oid, :phone, :path,
                    :target, :quoted, :fulfillment,
                    :usdt, :panora, :stellar, :order_usdc,
                    :haircut, :shortfall,
                    :oc_dep, :oc_swap, :tx_hash
                )
                ON DUPLICATE KEY UPDATE
                    fulfillment_ngn = VALUES(fulfillment_ngn),
                    stellar_deposit_usdc = COALESCE(VALUES(stellar_deposit_usdc), stellar_deposit_usdc),
                    haircut_ratio = COALESCE(VALUES(haircut_ratio), haircut_ratio),
                    ngn_shortfall = VALUES(ngn_shortfall),
                    oneclick_deposit_address = COALESCE(VALUES(oneclick_deposit_address), oneclick_deposit_address),
                    oneclick_swap_id = COALESCE(VALUES(oneclick_swap_id), oneclick_swap_id),
                    aptos_tx_hash = COALESCE(VALUES(aptos_tx_hash), aptos_tx_hash)'
            );
            $stmt->execute([
                'oid' => $orderId,
                'phone' => isset($row['user_phone']) ? trim((string) $row['user_phone']) : null,
                'path' => trim((string) ($row['offramp_path'] ?? 'unknown')),
                'target' => round($target, 2),
                'quoted' => isset($row['quoted_ngn_at_pin']) ? round((float) $row['quoted_ngn_at_pin'], 2) : null,
                'fulfillment' => round($fulfillment, 2),
                'usdt' => isset($row['usdt_debit']) ? (float) $row['usdt_debit'] : null,
                'panora' => $panora,
                'stellar' => $stellar,
                'order_usdc' => isset($row['centiiv_order_usdc']) ? (float) $row['centiiv_order_usdc'] : null,
                'haircut' => $haircutRatio,
                'shortfall' => round($shortfall, 2),
                'oc_dep' => isset($row['oneclick_deposit_address']) ? trim((string) $row['oneclick_deposit_address']) : null,
                'oc_swap' => isset($row['oneclick_swap_id']) ? trim((string) $row['oneclick_swap_id']) : null,
                'tx_hash' => isset($row['aptos_tx_hash']) ? trim((string) $row['aptos_tx_hash']) : null,
            ]);
            self::invalidateCache();

            return true;
        } catch (\Throwable $e) {
            error_log('SendraOfframpSlippageService recordOutcome: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return array{
     *   extra_ngn_p90: float,
     *   haircut_bps_p90: int,
     *   sample_count: int,
     *   haircut_samples: int,
     *   shortfall_samples: int
     * }
     */
    public static function getLearnedBuffers(): array
    {
        $cached = self::readCache();
        if ($cached !== null) {
            return $cached;
        }

        $maxRows = max(20, min(500, (int) ($_ENV['SENDRA_OFFRAMP_LEARN_MAX_ROWS'] ?? getenv('SENDRA_OFFRAMP_LEARN_MAX_ROWS') ?: 200)));
        $minSamples = max(5, (int) ($_ENV['SENDRA_OFFRAMP_LEARN_MIN_SAMPLES'] ?? getenv('SENDRA_OFFRAMP_LEARN_MIN_SAMPLES') ?: 10));

        $defaults = self::defaultBuffers();

        try {
            $db = self::aptosDb();
            $stmt = $db->prepare(
                'SELECT haircut_ratio, ngn_shortfall, panora_wallet_usdc
                 FROM sendra_offramp_outcomes
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $maxRows
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('SendraOfframpSlippageService getLearnedBuffers: ' . $e->getMessage());
            self::writeCache($defaults);

            return $defaults;
        }

        $haircuts = [];
        $shortfalls = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $hr = $r['haircut_ratio'] ?? null;
            if ($hr !== null && is_numeric($hr) && (float) $hr >= 0) {
                $haircuts[] = (float) $hr;
            }
            $sf = $r['ngn_shortfall'] ?? null;
            if ($sf !== null && is_numeric($sf) && (float) $sf >= 0) {
                $shortfalls[] = (float) $sf;
            }
        }

        $result = $defaults;
        $result['sample_count'] = count($rows);

        if (count($haircuts) >= $minSamples) {
            $p90Haircut = self::percentile($haircuts, 90);
            $envFloor = (int) ($_ENV['SENDRA_STELLAR_DEPOSIT_HAIRCUT_BPS'] ?? getenv('SENDRA_STELLAR_DEPOSIT_HAIRCUT_BPS') ?: 300);
            $learnedBps = (int) ceil($p90Haircut * 10000);
            $cap = (int) ($_ENV['SENDRA_OFFRAMP_LEARN_HAIRCUT_BPS_CAP'] ?? getenv('SENDRA_OFFRAMP_LEARN_HAIRCUT_BPS_CAP') ?: 800);
            $result['haircut_bps_p90'] = max($envFloor, min($cap, $learnedBps));
            $result['haircut_samples'] = count($haircuts);
        }

        if (count($shortfalls) >= $minSamples) {
            $p90Sf = self::percentile($shortfalls, 90);
            $capNgn = (float) ($_ENV['SENDRA_OFFRAMP_LEARN_NGN_CAP'] ?? getenv('SENDRA_OFFRAMP_LEARN_NGN_CAP') ?: 50);
            $result['extra_ngn_p90'] = min($capNgn, max(0.0, $p90Sf));
            $result['shortfall_samples'] = count($shortfalls);
        }

        self::writeCache($result);

        return $result;
    }

    /** Haircut bps for Centiiv order sizing (learned P90 when enough samples, else env). */
    public static function effectiveHaircutBps(): int
    {
        return self::getLearnedBuffers()['haircut_bps_p90'];
    }

    /** Extra NGN added to internal sizing target when SENDRA_OFFRAMP_EXTRA_NGN_BUFFER is set (>0). */
    public static function extraNgnBuffer(): float
    {
        $forced = trim((string) ($_ENV['SENDRA_OFFRAMP_EXTRA_NGN_BUFFER'] ?? getenv('SENDRA_OFFRAMP_EXTRA_NGN_BUFFER') ?: ''));
        if ($forced !== '' && is_numeric($forced)) {
            return max(0.0, (float) $forced);
        }

        return 0.0;
    }

    /**
     * @return array{extra_ngn_p90: float, haircut_bps_p90: int, sample_count: int, haircut_samples: int, shortfall_samples: int}
     */
    public static function buffersForDebug(): array
    {
        return self::getLearnedBuffers();
    }

    /**
     * @param list<float> $values
     */
    public static function percentile(array $values, float $pct): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        $n = count($values);
        if ($n === 1) {
            return (float) $values[0];
        }
        $pct = max(0.0, min(100.0, $pct));
        $rank = ($pct / 100.0) * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        if ($lo === $hi) {
            return (float) $values[$lo];
        }
        $weight = $rank - $lo;

        return (float) $values[$lo] * (1.0 - $weight) + (float) $values[$hi] * $weight;
    }

    /**
     * @return array{
     *   extra_ngn_p90: float,
     *   haircut_bps_p90: int,
     *   sample_count: int,
     *   haircut_samples: int,
     *   shortfall_samples: int
     * }
     */
    private static function defaultBuffers(): array
    {
        $envHaircut = (int) ($_ENV['SENDRA_STELLAR_DEPOSIT_HAIRCUT_BPS'] ?? getenv('SENDRA_STELLAR_DEPOSIT_HAIRCUT_BPS') ?: 300);
        $envExtra = (float) ($_ENV['SENDRA_OFFRAMP_LEARN_NGN_DEFAULT'] ?? getenv('SENDRA_OFFRAMP_LEARN_NGN_DEFAULT') ?: 5);

        return [
            'extra_ngn_p90' => max(0.0, $envExtra),
            'haircut_bps_p90' => max(0, min(2000, $envHaircut)),
            'sample_count' => 0,
            'haircut_samples' => 0,
            'shortfall_samples' => 0,
        ];
    }

    private static function aptosDb(): \PDO
    {
        $root = dirname(__DIR__, 2);
        $bootstrap = $root . '/config/bootstrap_env.php';
        if (is_readable($bootstrap)) {
            require_once $bootstrap;
        }
        $bots = require $root . '/config/Bots.php';
        $phoneId = (string) ($_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834');
        $dbConfig = $bots[$phoneId]['db_config'] ?? null;
        if (!is_array($dbConfig) || !is_array($dbConfig['live'] ?? null)) {
            throw new \RuntimeException('Aptos DB config missing');
        }
        if (($dbConfig['live']['host'] ?? '') === 'localhost') {
            $dbConfig['live']['host'] = '127.0.0.1';
        }
        if (is_array($dbConfig['replica'] ?? null) && ($dbConfig['replica']['host'] ?? '') === 'localhost') {
            $dbConfig['replica']['host'] = '127.0.0.1';
        }
        require_once $root . '/config/Database.php';

        return \Database::getInstance($dbConfig)->getConnection();
    }

    private static function cachePath(): string
    {
        $dir = dirname(__DIR__, 2) . '/var';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . self::CACHE_FILE;
    }

    /**
     * @return ?array{extra_ngn_p90: float, haircut_bps_p90: int, sample_count: int, haircut_samples: int, shortfall_samples: int}
     */
    private static function readCache(): ?array
    {
        $ttl = max(30, (int) ($_ENV['SENDRA_OFFRAMP_LEARN_CACHE_TTL'] ?? getenv('SENDRA_OFFRAMP_LEARN_CACHE_TTL') ?: 300));
        $path = self::cachePath();
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        $ts = (int) ($j['cached_at'] ?? 0);
        if ($ts <= 0 || time() - $ts > $ttl) {
            return null;
        }
        if (!isset($j['extra_ngn_p90'], $j['haircut_bps_p90'])) {
            return null;
        }

        return [
            'extra_ngn_p90' => (float) $j['extra_ngn_p90'],
            'haircut_bps_p90' => (int) $j['haircut_bps_p90'],
            'sample_count' => (int) ($j['sample_count'] ?? 0),
            'haircut_samples' => (int) ($j['haircut_samples'] ?? 0),
            'shortfall_samples' => (int) ($j['shortfall_samples'] ?? 0),
        ];
    }

    /**
     * @param array{extra_ngn_p90: float, haircut_bps_p90: int, sample_count: int, haircut_samples: int, shortfall_samples: int} $data
     */
    private static function writeCache(array $data): void
    {
        $payload = array_merge($data, ['cached_at' => time()]);
        @file_put_contents(self::cachePath(), json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private static function invalidateCache(): void
    {
        $path = self::cachePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
