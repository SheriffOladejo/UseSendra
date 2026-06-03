<?php

declare(strict_types=1);

namespace App\Services;

/**
 * NEAR Intents 1Click Swap API — Aptos → Stellar (etc.) for Sendra sell → Centiiv Stellar USDC rail.
 *
 * @see https://docs.near-intents.org/integration/distribution-channels/1click-api/quickstart
 */
final class SendraOneClickService
{
    /** 1Click rejects requests above this (HTTP 400 "quoteWaitingTimeMs is too long"). */
    private const QUOTE_WAIT_MAX_MS = 24000;

    private string $jwt;

    private string $baseUrl;

    public function __construct(?string $jwt = null, ?string $baseUrl = null)
    {
        $raw = $jwt ?? (string) ($_ENV['SENDRA_ONECLICK_JWT'] ?? getenv('SENDRA_ONECLICK_JWT') ?: '');
        $this->jwt = trim($raw);
        $this->baseUrl = rtrim($baseUrl ?? (string) ($_ENV['SENDRA_ONECLICK_BASE_URL'] ?? getenv('SENDRA_ONECLICK_BASE_URL') ?: 'https://1click.chaindefuser.com'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->jwt !== '';
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function requestQuote(array $body): array
    {
        if (!$this->isConfigured()) {
            return $this->err('SENDRA_ONECLICK_JWT not set', 0);
        }

        $first = $this->postJson('/v0/quote', $body);
        if ($first['success']) {
            return $first;
        }
        if (!$this->shouldRetryFailedQuote($first, $body)) {
            return $first;
        }
        $retryBody = $body;
        $retryMs = $this->resolveQuoteRetryWaitMs((int) ($body['quoteWaitingTimeMs'] ?? 0));
        $retryBody['quoteWaitingTimeMs'] = $retryMs;

        return $this->postJson('/v0/quote', $retryBody);
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function getStatus(string $depositAddress, ?string $depositMemo = null): array
    {
        if (!$this->isConfigured()) {
            return $this->err('SENDRA_ONECLICK_JWT not set', 0);
        }
        $q = 'depositAddress=' . rawurlencode($depositAddress);
        if ($depositMemo !== null && $depositMemo !== '') {
            $q .= '&depositMemo=' . rawurlencode($depositMemo);
        }

        return $this->getJson('/v0/status?' . $q);
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function submitDeposit(string $depositAddress, string $txHash, ?string $depositMemo = null): array
    {
        if (!$this->isConfigured()) {
            return $this->err('SENDRA_ONECLICK_JWT not set', 0);
        }
        $payload = ['depositAddress' => $depositAddress, 'txHash' => $txHash];
        if ($depositMemo !== null && $depositMemo !== '') {
            $payload['depositMemo'] = $depositMemo;
        }

        return $this->postJson('/v0/deposit/submit', $payload, false);
    }

    /**
     * Build quote body for Aptos USDT or USDC → Stellar USDC to a fixed recipient.
     *
     * @return array<string, mixed>
     */
    public static function buildAptosToStellarUsdcQuoteBody(
        string $originAssetId,
        string $destinationAssetId,
        string $amountSmallestOrigin,
        string $recipientStellarG,
        string $refundAptos0x,
        string $deadlineIsoUtc,
        bool $dry = false,
        ?int $quoteWaitingTimeMs = null
    ): array {
        return [
            'dry' => $dry,
            'depositMode' => 'SIMPLE',
            'swapType' => 'EXACT_INPUT',
            'slippageTolerance' => (int) ($_ENV['SENDRA_ONECLICK_SLIPPAGE_BPS'] ?? getenv('SENDRA_ONECLICK_SLIPPAGE_BPS') ?: 100),
            'originAsset' => $originAssetId,
            'depositType' => 'ORIGIN_CHAIN',
            'destinationAsset' => $destinationAssetId,
            'amount' => $amountSmallestOrigin,
            'recipient' => $recipientStellarG,
            'recipientType' => 'DESTINATION_CHAIN',
            'refundTo' => $refundAptos0x,
            'refundType' => 'ORIGIN_CHAIN',
            'deadline' => $deadlineIsoUtc,
            'quoteWaitingTimeMs' => $quoteWaitingTimeMs ?? self::resolveConfiguredQuoteWaitMs(),
        ];
    }

    /**
     * Buy path: Stellar USDC → Aptos USDT/USDC (Centiiv dispenses Stellar USDC to 1Click deposit).
     * Stellar origin requires depositMode MEMO (address + memo from live quote).
     *
     * @return array<string, mixed>
     */
    public static function buildStellarToAptosQuoteBody(
        string $originStellarUsdcAssetId,
        string $destinationAptosAssetId,
        string $amountSmallestStellarUsdc,
        string $recipientAptos0x,
        string $refundStellarG,
        string $deadlineIsoUtc,
        bool $dry = false,
        ?int $quoteWaitingTimeMs = null,
        string $swapType = 'EXACT_INPUT'
    ): array {
        return [
            'dry' => $dry,
            'depositMode' => 'MEMO',
            'swapType' => $swapType,
            'slippageTolerance' => (int) ($_ENV['SENDRA_ONECLICK_SLIPPAGE_BPS'] ?? getenv('SENDRA_ONECLICK_SLIPPAGE_BPS') ?: 100),
            'originAsset' => $originStellarUsdcAssetId,
            'depositType' => 'ORIGIN_CHAIN',
            'destinationAsset' => $destinationAptosAssetId,
            'amount' => $amountSmallestStellarUsdc,
            'recipient' => $recipientAptos0x,
            'recipientType' => 'DESTINATION_CHAIN',
            'refundTo' => $refundStellarG,
            'refundType' => 'ORIGIN_CHAIN',
            'deadline' => $deadlineIsoUtc,
            'quoteWaitingTimeMs' => $quoteWaitingTimeMs ?? self::resolveConfiguredQuoteWaitMs(),
        ];
    }

    /**
     * Shorter solver wait for sizing / binary-search dry quotes (live + final lock use {@see resolveConfiguredQuoteWaitMs}).
     */
    public static function resolveSearchQuoteWaitMs(): int
    {
        $raw = $_ENV['SENDRA_ONECLICK_SEARCH_QUOTE_WAIT_MS'] ?? getenv('SENDRA_ONECLICK_SEARCH_QUOTE_WAIT_MS');
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if ($raw !== false && $raw !== null && $raw !== '') {
            return self::clampQuoteWaitMs((int) $raw);
        }
        $full = self::resolveConfiguredQuoteWaitMs();

        return self::clampQuoteWaitMs(min(4000, $full > 0 ? $full : 4000));
    }

    /**
     * Primary quote wait. When unset, defaults to 8000ms so solvers can respond (0 often yields HTTP 400 "Failed to get quote").
     */
    public static function resolveConfiguredQuoteWaitMs(): int
    {
        $raw = $_ENV['SENDRA_ONECLICK_QUOTE_WAIT_MS'] ?? getenv('SENDRA_ONECLICK_QUOTE_WAIT_MS');
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if ($raw !== false && $raw !== null && $raw !== '') {
            return self::clampQuoteWaitMs((int) $raw);
        }

        return self::clampQuoteWaitMs(8000);
    }

    private static function clampQuoteWaitMs(int $ms): int
    {
        if ($ms <= 0) {
            return $ms;
        }

        return min($ms, self::QUOTE_WAIT_MAX_MS);
    }

    private function resolveQuoteRetryWaitMs(int $firstWaitMs): int
    {
        $raw = $_ENV['SENDRA_ONECLICK_QUOTE_RETRY_WAIT_MS'] ?? getenv('SENDRA_ONECLICK_QUOTE_RETRY_WAIT_MS');
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        $configured = ($raw !== false && $raw !== null && $raw !== '') ? (int) $raw : 15000;

        return self::clampQuoteWaitMs(max($configured, max(8000, $firstWaitMs * 2)));
    }

    /**
     * @param array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string} $result
     * @param array<string, mixed> $body
     */
    private function shouldRetryFailedQuote(array $result, array $body): bool
    {
        $noRetry = ($_ENV['SENDRA_ONECLICK_QUOTE_NO_RETRY'] ?? getenv('SENDRA_ONECLICK_QUOTE_NO_RETRY')) === '1';
        if ($noRetry) {
            return false;
        }
        if (($result['http_code'] ?? 0) !== 400 || !empty($result['success'])) {
            return false;
        }
        $err = strtolower((string) ($result['error'] ?? ''));
        $msg = '';
        if (isset($result['data']['message']) && is_string($result['data']['message'])) {
            $msg = strtolower($result['data']['message']);
        }
        if (!str_contains($err, 'failed to get quote') && !str_contains($msg, 'failed to get quote')) {
            return false;
        }
        $firstWait = (int) ($body['quoteWaitingTimeMs'] ?? 0);
        $retryMs = $this->resolveQuoteRetryWaitMs($firstWait);

        return $retryMs > $firstWait;
    }

    /**
     * @param array<string, mixed> $quoteResponse decoded JSON from /v0/quote
     * @return array{correlation_id: ?string, deposit_address: ?string, deposit_memo: ?string, amount_in: ?string, amount_out: ?string, amount_out_formatted: ?string, signature: ?string, raw_quote: array<string, mixed>}
     */
    public static function parseQuoteResponse(array $quoteResponse): array
    {
        $correlationId = isset($quoteResponse['correlationId']) ? (string) $quoteResponse['correlationId'] : null;
        $signature = isset($quoteResponse['signature']) ? (string) $quoteResponse['signature'] : null;
        $q = $quoteResponse['quote'] ?? null;
        if (!is_array($q)) {
            $q = $quoteResponse['data'] ?? $quoteResponse['result'] ?? [];
        }
        if (!is_array($q)) {
            $q = [];
        }

        // 1Click has changed response shapes over time. Be permissive: accept either
        // the legacy `{ quote: {...} }` or flatter envelopes.
        $deposit = null;
        foreach ([$q['depositAddress'] ?? null, $quoteResponse['depositAddress'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') {
                $deposit = $cand;
                break;
            }
        }

        $memo = null;
        foreach ([$q['depositMemo'] ?? null, $quoteResponse['depositMemo'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') {
                $memo = $cand;
                break;
            }
        }
        if ($memo === '') {
            $memo = null;
        }

        $amountIn = null;
        foreach ([$q['amountIn'] ?? null, $quoteResponse['amountIn'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') {
                $amountIn = $cand;
                break;
            }
            if (is_numeric($cand)) {
                $amountIn = (string) $cand;
                break;
            }
        }

        $amountOut = null;
        foreach ([$q['amountOut'] ?? null, $quoteResponse['amountOut'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') {
                $amountOut = $cand;
                break;
            }
            if (is_numeric($cand)) {
                $amountOut = (string) $cand;
                break;
            }
        }

        $amountOutFormatted = null;
        foreach ([$q['amountOutFormatted'] ?? null, $quoteResponse['amountOutFormatted'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') {
                $amountOutFormatted = $cand;
                break;
            }
            if (is_numeric($cand)) {
                $amountOutFormatted = (string) $cand;
                break;
            }
        }

        return [
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
            'deposit_address' => $deposit !== '' ? $deposit : null,
            'deposit_memo' => $memo,
            'amount_in' => $amountIn,
            'amount_out' => $amountOut,
            'amount_out_formatted' => $amountOutFormatted,
            'signature' => $signature !== '' ? $signature : null,
            'raw_quote' => $quoteResponse,
        ];
    }

    /**
     * @param array<string, mixed> $statusData decoded JSON from /v0/status
     */
    public static function parseStatus(array $statusData): string
    {
        $s = $statusData['status'] ?? '';

        return is_string($s) ? strtoupper(trim($s)) : '';
    }

    public static function cryptoAmountToSmallestUnits(string $amountStr, int $decimals): string
    {
        $amountStr = trim($amountStr);
        if ($amountStr === '' || !is_numeric($amountStr)) {
            return '0';
        }
        $scale = (string) (10 ** max(0, min(18, $decimals)));
        if (function_exists('bcmul')) {
            return bcmul($amountStr, $scale, 0);
        }

        return (string) (int) round((float) $amountStr * (float) $scale);
    }

    public static function smallestUnitsToFloatString(string $smallest, int $decimals): string
    {
        if (!ctype_digit($smallest)) {
            return '0';
        }
        if (function_exists('bcdiv')) {
            $scale = (string) (10 ** max(0, min(18, $decimals)));

            return bcdiv($smallest, $scale, min(18, $decimals));
        }

        return (string) ((float) $smallest / (10 ** $decimals));
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function postJson(string $path, array $body, bool $withAuth = true): array
    {
        $url = $this->baseUrl . $path;
        $headers = ['Content-Type: application/json'];
        if ($withAuth) {
            $headers[] = 'Authorization: Bearer ' . $this->jwt;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Sendra/1.0 (+https://usesendra.com)',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            if ($this->isTraceEnabled()) {
                $this->trace('POST ' . $path, ['http' => $code, 'curl_error' => $err, 'baseUrl' => $this->baseUrl]);
            }

            return $this->err($err, $code, null);
        }
        curl_close($ch);
        $out = $this->decodeResponse($raw, $code);
        if ($this->isTraceEnabled()) {
            $this->trace('POST ' . $path, $this->flattenApiResult($out, $raw, $body, $path, 'POST'));
        }

        return $out;
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function getJson(string $pathWithQuery): array
    {
        $url = $this->baseUrl . $pathWithQuery;
        $headers = ['Authorization: Bearer ' . $this->jwt];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'Sendra/1.0 (+https://usesendra.com)',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            if ($this->isTraceEnabled()) {
                $this->trace('GET ' . (strlen($pathWithQuery) > 200 ? (substr($pathWithQuery, 0, 200) . '…') : $pathWithQuery), ['http' => $code, 'curl_error' => $err]);
            }

            return $this->err($err, $code, null);
        }
        curl_close($ch);
        $out = $this->decodeResponse($raw, $code);
        if ($this->isTraceEnabled()) {
            $qShort = strlen($pathWithQuery) > 220 ? (substr($pathWithQuery, 0, 220) . '…') : $pathWithQuery;
            $this->trace('GET ' . $qShort, $this->flattenApiResult($out, $raw, null, $pathWithQuery, 'GET'));
        }

        return $out;
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function decodeResponse(?string $raw, int $code): array
    {
        if ($raw === null || $raw === '') {
            return $this->err('Empty response', $code, $raw);
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->err('Invalid JSON: ' . $e->getMessage(), $code, $raw);
        }
        if (!is_array($data)) {
            return $this->err('Invalid JSON shape', $code, $raw);
        }
        $ok = $code >= 200 && $code < 300 && !isset($data['statusCode']);

        return [
            'success' => $ok,
            'http_code' => $code,
            'data' => $data,
            'raw' => $raw,
            'error' => $ok ? null : (string) ($data['message'] ?? $data['error'] ?? 'HTTP ' . $code),
        ];
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function err(string $message, int $code, ?string $raw = null): array
    {
        return [
            'success' => false,
            'http_code' => $code,
            'data' => [],
            'raw' => $raw,
            'error' => $message,
        ];
    }

    /**
     * Per-request API tracing to error_log (php-debug.log if configured). Set SENDRA_1CLICK_TRACE=0 to reduce noise.
     */
    private function isTraceEnabled(): bool
    {
        $v = strtolower(trim((string) ($_ENV['SENDRA_1CLICK_TRACE'] ?? getenv('SENDRA_1CLICK_TRACE') ?: '')));
        if ($v === '') {
            return true;
        }

        return !in_array($v, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param array<int|string, mixed> $line
     */
    private function trace(string $op, array $line): void
    {
        $line['1click_base'] = $this->baseUrl;
        error_log('[Sendra 1Click] ' . $op . ' ' . json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string} $out
     * @param ?array<string, mixed> $requestBody
     * @return array<int|string, mixed>
     */
    private function flattenApiResult(array $out, ?string $raw, ?array $requestBody, string $ctxPath, string $method): array
    {
        $summary = self::dataSummary($out['data'] ?? [], 6000);
        $rawT = is_string($raw) ? (strlen($raw) > 5000 ? (substr($raw, 0, 5000) . '…') : $raw) : null;
        $reqJ = is_array($requestBody) && $this->isTraceEnabled() ? (strlen($r = (string) json_encode($requestBody, JSON_UNESCAPED_UNICODE)) > 2000 ? substr($r, 0, 2000) . '…' : $r) : null;
        $parsed = [];
        if (is_array($out['data'] ?? null)) {
            $parsed = [
                'status' => is_string($out['data']['status'] ?? null) ? $out['data']['status'] : (isset($out['data']['status']) ? (string) $out['data']['status'] : null),
                'correlationId' => is_string($out['data']['correlationId'] ?? null) ? $out['data']['correlationId'] : null,
            ];
        }
        if ($parsed['status'] === null && is_array($out['data'] ?? null)) {
            if (is_string($out['data']['message'] ?? null)) {
                $parsed['message'] = (string) $out['data']['message'];
            }
        }

        $row = [
            'method' => $method,
            'path' => $ctxPath,
            'http' => $out['http_code'] ?? 0,
            'ok' => (bool) ($out['success'] ?? false),
            'error' => $out['error'] ?? null,
            'parsed' => $parsed,
            'request' => $reqJ,
            'data_summary' => $summary,
            'raw_trunc' => $rawT,
        ];
        if ($row['error'] === null) {
            unset($row['error']);
        }
        if ($row['request'] === null) {
            unset($row['request']);
        }
        if ($row['raw_trunc'] === null) {
            unset($row['raw_trunc']);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function dataSummary(array $data, int $maxLen): string
    {
        if ($data === []) {
            return '[]';
        }
        $j = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!is_string($j)) {
            return '…';
        }
        if (strlen($j) > $maxLen) {
            return substr($j, 0, $maxLen) . '…';
        }

        return $j;
    }
}
