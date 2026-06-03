<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Probes Centiiv, 1Click, and optional NEAR status; stores JSON for the WhatsApp bot to gate sells.
 *
 * Cron: php src/scripts/cron_ramp_health.php
 *
 * @see https://status.near-intents.org/ (informational fetch only; not used to block by default)
 */
final class SendraRampHealthService
{
    /**
     * @return array<string, mixed>
     */
    public static function defaultSnapshotNoCache(): array
    {
        return [
            'ok' => true,
            'checked_at' => gmdate('c'),
            'no_cache' => true,
            'stale' => true,
            'oneclick_tokens' => ['ok' => true, 'skipped' => true, 'note' => 'no cache yet — run cron_ramp_health.php'],
            'oneclick_stellar' => ['ok' => true, 'skipped' => true],
            'centiiv' => ['ok' => true, 'skipped' => true],
            'near_status' => null,
        ];
    }

    public static function defaultCachePath(): string
    {
        $p = $_ENV['SENDRA_RAMP_HEALTH_CACHE'] ?? getenv('SENDRA_RAMP_HEALTH_CACHE');
        if (is_string($p) && trim($p) !== '') {
            return trim($p);
        }

        return dirname(__DIR__, 2) . '/var/ramp_health.json';
    }

    public static function maxCacheAgeSeconds(): int
    {
        $raw = $_ENV['SENDRA_RAMP_HEALTH_MAX_AGE'] ?? getenv('SENDRA_RAMP_HEALTH_MAX_AGE');
        if (is_string($raw) && trim($raw) !== '') {
            return max(30, (int) $raw);
        }

        return 180;
    }

    public static function isHealthGateEnabled(): bool
    {
        $v = strtolower(trim((string) ($_ENV['SENDRA_RAMP_HEALTH_GATE'] ?? getenv('SENDRA_RAMP_HEALTH_GATE') ?: '1')));

        return $v !== '0' && $v !== 'false' && $v !== 'off';
    }

    /**
     * When true, missing or stale cache does not block sells (default).
     */
    public static function failOpenWithoutFreshCache(): bool
    {
        $v = strtolower(trim((string) ($_ENV['SENDRA_RAMP_HEALTH_FAIL_OPEN'] ?? getenv('SENDRA_RAMP_HEALTH_FAIL_OPEN') ?: '1')));

        return $v !== '0' && $v !== 'false' && $v !== 'off';
    }

    /**
     * @return array<string, mixed>
     */
    public static function readCacheFile(?string $path = null): ?array
    {
        $path = $path ?? self::defaultCachePath();
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($j) ? $j : null;
    }

    /**
     * @param array<string, mixed> $cached
     */
    public static function cacheIsFresh(array $cached): bool
    {
        $ts = $cached['checked_at'] ?? null;
        if (!is_string($ts) || $ts === '') {
            return false;
        }
        $t = strtotime($ts);
        if ($t === false) {
            return false;
        }

        return (time() - $t) <= self::maxCacheAgeSeconds();
    }

    /**
     * @return array{block: bool, message: string, snapshot: array<string, mixed>}
     */
    public static function evaluateSellGate(bool $centiivConfigured, bool $oneclickRampConfigured): array
    {
        if (!self::isHealthGateEnabled()) {
            return ['block' => false, 'message' => '', 'snapshot' => ['gate' => 'disabled']];
        }

        $cached = self::readCacheFile();
        if ($cached === null) {
            $snap = self::defaultSnapshotNoCache();
            if (self::failOpenWithoutFreshCache()) {
                return ['block' => false, 'message' => '', 'snapshot' => $snap];
            }

            return [
                'block' => true,
                'message' => self::formatUserDownMessage(['centiiv' => false, 'oneclick_api' => false, 'stellar_route' => false], 'health cache missing — ops: run cron_ramp_health.php'),
                'snapshot' => $snap,
            ];
        }

        $fresh = self::cacheIsFresh($cached);
        if (!$fresh && self::failOpenWithoutFreshCache()) {
            return ['block' => false, 'message' => '', 'snapshot' => array_merge($cached, ['stale' => true])];
        }

        if (!$fresh && !self::failOpenWithoutFreshCache()) {
            return [
                'block' => true,
                'message' => self::formatUserDownMessage(['centiiv' => false, 'oneclick_api' => false, 'stellar_route' => false], 'health data is stale'),
                'snapshot' => array_merge($cached, ['stale' => true]),
            ];
        }

        $flags = self::deriveFailureFlags($cached, $centiivConfigured, $oneclickRampConfigured);

        if (!$flags['centiiv'] && !$flags['oneclick_api'] && !$flags['stellar_route']) {
            return ['block' => false, 'message' => '', 'snapshot' => $cached];
        }

        return [
            'block' => true,
            'message' => self::formatUserDownMessage($flags, (string) ($cached['summary'] ?? '')),
            'snapshot' => $cached,
        ];
    }

    /**
     * @param array<string, mixed> $cached
     * @return array{centiiv: bool, oneclick_api: bool, stellar_route: bool}
     */
    private static function deriveFailureFlags(array $cached, bool $centiivConfigured, bool $oneclickRampConfigured): array
    {
        $centiivFail = false;
        $apiFail = false;
        $stellarFail = false;

        if ($centiivConfigured) {
            $c = $cached['centiiv'] ?? null;
            if (is_array($c) && empty($c['skipped'])) {
                $centiivFail = empty($c['ok']);
            }
        }

        if ($oneclickRampConfigured) {
            $t = $cached['oneclick_tokens'] ?? null;
            if (is_array($t) && empty($t['skipped'])) {
                $apiFail = empty($t['ok']);
            }
            $s = $cached['oneclick_stellar'] ?? null;
            if (is_array($s) && empty($s['skipped'])) {
                $stellarFail = empty($s['ok']);
            }
        }

        return [
            'centiiv' => $centiivFail,
            'oneclick_api' => $apiFail,
            'stellar_route' => $stellarFail,
        ];
    }

    /**
     * @param array{centiiv: bool, oneclick_api: bool, stellar_route: bool} $flags
     */
    private static function formatUserDownMessage(array $flags, string $hint): string
    {
        $parts = [];
        if ($flags['centiiv']) {
            $parts[] = 'bank payout provider';
        }
        if ($flags['oneclick_api']) {
            $parts[] = 'swap provider';
        }
        if ($flags['stellar_route']) {
            $parts[] = 'cross-chain route';
        }
        $list = $parts === [] ? 'a dependency' : implode(', ', $parts);

        return "⚠️ *Cash-out is paused*\n\nOur systems report an issue with {$list}. " .
            "You can try again in a few minutes.\n\n" .
            "If this continues, email *support@usesendra.com*.";
    }

    /**
     * Run all probes and write cache file. Intended for cron.
     *
     * @return array<string, mixed>
     */
    public static function runProbesAndWriteCache(?string $path = null): array
    {
        $path = $path ?? self::defaultCachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $oneclickBase = rtrim((string) ($_ENV['SENDRA_ONECLICK_BASE_URL'] ?? getenv('SENDRA_ONECLICK_BASE_URL') ?: 'https://1click.chaindefuser.com'), '/');

        $out = [
            'checked_at' => gmdate('c'),
            'oneclick_base' => $oneclickBase,
            'oneclick_tokens' => self::probeOneClickTokens($oneclickBase),
            'oneclick_stellar' => self::probeOneClickStellarQuote(),
            'centiiv' => self::probeCentiivBanks(),
            'near_status' => self::probeNearStatusPage(),
        ];

        $summaryBits = [];
        if (is_array($out['oneclick_tokens']) && empty($out['oneclick_tokens']['ok']) && empty($out['oneclick_tokens']['skipped'])) {
            $summaryBits[] = '1Click tokens endpoint';
        }
        if (is_array($out['oneclick_stellar']) && empty($out['oneclick_stellar']['ok']) && empty($out['oneclick_stellar']['skipped'])) {
            $summaryBits[] = '1Click Stellar quote';
        }
        if (is_array($out['centiiv']) && empty($out['centiiv']['ok']) && empty($out['centiiv']['skipped'])) {
            $summaryBits[] = 'Centiiv banking/banks';
        }
        $out['summary'] = $summaryBits === [] ? 'ok' : implode('; ', $summaryBits) . ' failing';

        $out['ok'] = $summaryBits === [];

        @file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function probeOneClickTokens(string $baseUrl): array
    {
        $t0 = microtime(true);
        $url = $baseUrl . '/v0/tokens';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'SendraRampHealth/1.0',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);

            return ['ok' => false, 'http' => $code, 'ms' => round((microtime(true) - $t0) * 1000, 2), 'error' => $err];
        }
        curl_close($ch);
        if ($code !== 200 || !is_string($raw) || $raw === '') {
            return ['ok' => false, 'http' => $code, 'ms' => round((microtime(true) - $t0) * 1000, 2), 'error' => 'non-200 or empty'];
        }
        try {
            $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'http' => $code, 'ms' => round((microtime(true) - $t0) * 1000, 2), 'error' => 'invalid json: ' . $e->getMessage()];
        }
        $tokens = $j['tokens'] ?? $j;
        $ok = is_array($tokens) && ($tokens !== [] || isset($j[0]));

        return ['ok' => $ok, 'http' => $code, 'ms' => round((microtime(true) - $t0) * 1000, 2), 'error' => $ok ? null : 'unexpected shape'];
    }

    /**
     * Dry quote Aptos USDC → pooled Stellar USDC (same path as production USDC sells).
     *
     * @return array<string, mixed>
     */
    private static function probeOneClickStellarQuote(): array
    {
        $t0 = microtime(true);
        $oneClick = new SendraOneClickService();
        if (!$oneClick->isConfigured()) {
            return ['ok' => true, 'skipped' => true, 'note' => 'SENDRA_ONECLICK_JWT unset'];
        }
        $stellar = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
        if ($stellar === '' || strlen($stellar) < 5 || $stellar[0] !== 'G') {
            return ['ok' => true, 'skipped' => true, 'note' => 'SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT unset'];
        }
        $refund = trim((string) ($_ENV['SENDRA_RAMP_HEALTH_PROBE_REFUND_APTOS'] ?? getenv('SENDRA_RAMP_HEALTH_PROBE_REFUND_APTOS') ?: ''));
        if ($refund === '' || !str_starts_with(strtolower($refund), '0x') || strlen($refund) < 10) {
            return ['ok' => true, 'skipped' => true, 'note' => 'set SENDRA_RAMP_HEALTH_PROBE_REFUND_APTOS for Stellar route probe'];
        }
        $originUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near'));
        $dest = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));
        $deadline = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
        $body = SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
            $originUsdc,
            $dest,
            '1000000',
            $stellar,
            $refund,
            $deadline,
            true
        );
        $res = $oneClick->requestQuote($body);
        $ms = round((microtime(true) - $t0) * 1000, 2);
        if (!empty($res['success'])) {
            return ['ok' => true, 'http' => (int) ($res['http_code'] ?? 200), 'ms' => $ms];
        }
        $cid = null;
        if (isset($res['data']['correlationId']) && is_string($res['data']['correlationId'])) {
            $cid = $res['data']['correlationId'];
        }

        return [
            'ok' => false,
            'http' => (int) ($res['http_code'] ?? 0),
            'ms' => $ms,
            'error' => (string) ($res['error'] ?? 'quote failed'),
            'correlationId' => $cid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function probeCentiivBanks(): array
    {
        $t0 = microtime(true);
        $agg = new SendraCentiivRampService();
        if (!$agg->isConfigured()) {
            return ['ok' => true, 'skipped' => true, 'note' => 'SENDRA_CENTIIV_JWT unset'];
        }
        $r = $agg->getBanks();
        $ms = round((microtime(true) - $t0) * 1000, 2);
        if (!empty($r['success'])) {
            return ['ok' => true, 'http' => (int) ($r['http_code'] ?? 200), 'ms' => $ms];
        }

        return [
            'ok' => false,
            'http' => (int) ($r['http_code'] ?? 0),
            'ms' => $ms,
            'error' => (string) ($r['error'] ?? 'getBanks failed'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function probeNearStatusPage(): ?array
    {
        $url = trim((string) ($_ENV['SENDRA_RAMP_NEAR_STATUS_URL'] ?? getenv('SENDRA_RAMP_NEAR_STATUS_URL') ?: 'https://status.near-intents.org/'));
        if ($url === '') {
            return null;
        }
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SendraRampHealth/1.0',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);

            return ['ok' => false, 'http' => $code, 'ms' => round((microtime(true) - $t0) * 1000, 2), 'error' => $err, 'informational' => true];
        }
        curl_close($ch);
        $ok = $code >= 200 && $code < 400;

        return ['ok' => $ok, 'http' => $code, 'ms' => round((microtime(true) - $t0) * 1000, 2), 'informational' => true];
    }
}
