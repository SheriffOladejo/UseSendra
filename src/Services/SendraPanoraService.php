<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Panora swap API — Aptos USDT → USDC (same-wallet) before 1Click → Centiiv off-ramp.
 *
 * @see https://docs.panora.exchange/developer/swap/api
 */
final class SendraPanoraService
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $raw = $apiKey ?? (string) ($_ENV['SENDRA_PANORA_API_KEY'] ?? getenv('SENDRA_PANORA_API_KEY') ?: '');
        $this->apiKey = trim($raw);
        $this->baseUrl = rtrim($baseUrl ?? (string) ($_ENV['SENDRA_PANORA_API_BASE_URL'] ?? getenv('SENDRA_PANORA_API_BASE_URL') ?: 'https://api.panora.exchange'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * USDT→USDC via Panora + 1Click/Centiiv rail (env flag + Panora key + 1Click ramp).
     */
    public static function isUsdtOfframpEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['SENDRA_PANORA_USDT_OFFRAMP'] ?? getenv('SENDRA_PANORA_USDT_OFFRAMP') ?: '')));
        if ($flag !== '1' && $flag !== 'true' && $flag !== 'on' && $flag !== 'yes') {
            return false;
        }
        $panora = new self();

        return $panora->isConfigured();
    }

    /** In-wallet USDT ↔ USDC via Panora (WhatsApp `swap` command). */
    public static function isWalletSwapEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['SENDRA_PANORA_WALLET_SWAP'] ?? getenv('SENDRA_PANORA_WALLET_SWAP') ?: '')));
        if (in_array($flag, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        if ($flag === '1' || $flag === 'true' || $flag === 'on' || $flag === 'yes') {
            return (new self())->isConfigured();
        }

        return false;
    }

    /**
     * @return 'usdt_to_usdc'|'usdc_to_usdt'
     */
    public static function normalizeSwapDirection(string $fromAsset, string $toAsset): ?string
    {
        $from = strtoupper(trim($fromAsset));
        $to = strtoupper(trim($toAsset));
        if ($from === 'USDT' && $to === 'USDC') {
            return 'usdt_to_usdc';
        }
        if ($from === 'USDC' && $to === 'USDT') {
            return 'usdc_to_usdt';
        }

        return null;
    }

    /**
     * @param 'usdt_to_usdc'|'usdc_to_usdt' $direction
     * @return array{from: string, to: string}
     */
    public static function tokenAddressesForDirection(string $direction): array
    {
        if ($direction === 'usdc_to_usdt') {
            return ['from' => self::usdcMetadataAddress(), 'to' => self::usdtMetadataAddress()];
        }

        return ['from' => self::usdtMetadataAddress(), 'to' => self::usdcMetadataAddress()];
    }

    /**
     * Exact-in swap quote for either direction (amount is the *from* token).
     *
     * @param 'usdt_to_usdc'|'usdc_to_usdt' $direction
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function requestSwapQuoteForDirection(string $direction, string $fromTokenAmount, string $toWalletAddress0x): array
    {
        if (!$this->isConfigured()) {
            return $this->err('SENDRA_PANORA_API_KEY not set', 0);
        }
        $toWalletAddress0x = trim($toWalletAddress0x);
        if ($toWalletAddress0x === '') {
            return $this->err('toWalletAddress required', 0);
        }
        $addrs = self::tokenAddressesForDirection($direction);
        $body = [
            'chainId' => self::resolveChainId(),
            'fromTokenAddress' => $addrs['from'],
            'toTokenAddress' => $addrs['to'],
            'fromTokenAmount' => $fromTokenAmount,
            'toWalletAddress' => $toWalletAddress0x,
            'slippagePercentage' => self::resolveSlippagePercent(),
        ];

        return $this->postJson('/swap', $body);
    }

    public static function resolveChainId(): string
    {
        $raw = trim((string) ($_ENV['SENDRA_PANORA_CHAIN_ID'] ?? getenv('SENDRA_PANORA_CHAIN_ID') ?: ''));
        if ($raw !== '') {
            return $raw;
        }

        $net = strtoupper(trim((string) ($_ENV['APTOS_NETWORK'] ?? getenv('APTOS_NETWORK') ?: 'MAINNET')));

        return $net === 'MAINNET' ? '1' : '2';
    }

    public static function resolveSlippagePercent(): string
    {
        $raw = trim((string) ($_ENV['SENDRA_PANORA_SLIPPAGE_PERCENT'] ?? getenv('SENDRA_PANORA_SLIPPAGE_PERCENT') ?: '1'));
        if ($raw === '') {
            return '1';
        }

        return $raw;
    }

    public static function usdtMetadataAddress(): string
    {
        $v = trim((string) ($_ENV['SENDRA_PANORA_USDT_METADATA'] ?? getenv('SENDRA_PANORA_USDT_METADATA') ?: ''));
        if ($v !== '') {
            return $v;
        }
        $net = strtoupper(trim((string) ($_ENV['APTOS_NETWORK'] ?? getenv('APTOS_NETWORK') ?: 'MAINNET')));

        return $net === 'MAINNET'
            ? '0x357b0b74bc833e95a115ad22604854d6b0fca151cecd94111770e5d6ffc9dc2b'
            : '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a';
    }

    public static function usdcMetadataAddress(): string
    {
        $v = trim((string) ($_ENV['SENDRA_PANORA_USDC_METADATA'] ?? getenv('SENDRA_PANORA_USDC_METADATA') ?: ''));
        if ($v !== '') {
            return $v;
        }
        $net = strtoupper(trim((string) ($_ENV['APTOS_NETWORK'] ?? getenv('APTOS_NETWORK') ?: 'MAINNET')));

        return $net === 'MAINNET'
            ? '0xbae207659db88bea0cbead6da0ed00aac12edcdda169e591cd41c94180b46f3b'
            : '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832';
    }

    /**
     * Exact-in swap quote: USDT from user wallet → USDC to same wallet (`toWalletAddress`).
     *
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function requestSwapQuote(string $fromTokenAmount, string $toWalletAddress0x): array
    {
        if (!$this->isConfigured()) {
            return $this->err('SENDRA_PANORA_API_KEY not set', 0);
        }
        $toWalletAddress0x = trim($toWalletAddress0x);
        if ($toWalletAddress0x === '') {
            return $this->err('toWalletAddress required', 0);
        }

        $body = [
            'chainId' => self::resolveChainId(),
            'fromTokenAddress' => self::usdtMetadataAddress(),
            'toTokenAddress' => self::usdcMetadataAddress(),
            'fromTokenAmount' => $fromTokenAmount,
            'toWalletAddress' => $toWalletAddress0x,
            'slippagePercentage' => self::resolveSlippagePercent(),
        ];

        return $this->postJson('/swap', $body);
    }

    /**
     * Quote USDT→USDC for a target USDC amount. Tries exact-out first, then exact-in estimate on rate limits.
     *
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function requestSwapQuoteForTargetUsdc(string $targetUsdcStr, string $toWalletAddress0x): array
    {
        $out = $this->requestSwapQuoteExactOut($targetUsdcStr, $toWalletAddress0x);
        if ($out['success']) {
            return $out;
        }
        $http = (int) ($out['http_code'] ?? 0);
        if ($http !== 429 && $http !== 503) {
            return $out;
        }
        $targetUsdc = (float) $targetUsdcStr;
        if ($targetUsdc <= 0) {
            return $out;
        }
        $usdtGuess = rtrim(rtrim(number_format($targetUsdc * 1.008, 6, '.', ''), '0'), '.');
        if ($usdtGuess === '') {
            $usdtGuess = (string) round($targetUsdc * 1.008, 6);
        }

        return $this->requestSwapQuote($usdtGuess, $toWalletAddress0x);
    }

    /**
     * Exact-out: target USDC in user wallet → minimum USDT in (Panora supports toTokenAmount).
     *
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function requestSwapQuoteExactOut(string $toTokenAmount, string $toWalletAddress0x): array
    {
        if (!$this->isConfigured()) {
            return $this->err('SENDRA_PANORA_API_KEY not set', 0);
        }
        $toWalletAddress0x = trim($toWalletAddress0x);
        if ($toWalletAddress0x === '') {
            return $this->err('toWalletAddress required', 0);
        }

        $body = [
            'chainId' => self::resolveChainId(),
            'fromTokenAddress' => self::usdtMetadataAddress(),
            'toTokenAddress' => self::usdcMetadataAddress(),
            'toTokenAmount' => $toTokenAmount,
            'toWalletAddress' => $toWalletAddress0x,
            'slippagePercentage' => self::resolveSlippagePercent(),
        ];

        return $this->postJson('/swap', $body);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   from_token_amount: ?float,
     *   to_token_amount: ?float,
     *   min_to_token_amount: ?float,
     *   quote: ?array<string, mixed>,
     *   tx_data: ?array<string, mixed>
     * }
     */
    public static function parseSwapResponse(array $data): array
    {
        $quotes = $data['quotes'] ?? null;
        if (!is_array($quotes) || $quotes === []) {
            return [
                'from_token_amount' => null,
                'to_token_amount' => null,
                'min_to_token_amount' => null,
                'quote' => null,
                'tx_data' => null,
            ];
        }
        $q = $quotes[0];
        if (!is_array($q)) {
            return [
                'from_token_amount' => null,
                'to_token_amount' => null,
                'min_to_token_amount' => null,
                'quote' => null,
                'tx_data' => null,
            ];
        }
        $from = self::parseQuoteTokenAmount($q['fromTokenAmount'] ?? $q['from_token_amount'] ?? null);
        $to = self::parseQuoteTokenAmount($q['toTokenAmount'] ?? $q['to_token_amount'] ?? null);
        $min = self::parseQuoteTokenAmount($q['minToTokenAmount'] ?? $q['min_to_token_amount'] ?? null);
        if ($to === null && isset($data['toTokenAmount'])) {
            $to = self::parseQuoteTokenAmount($data['toTokenAmount']);
        }
        $tx = isset($q['txData']) && is_array($q['txData']) ? $q['txData'] : null;

        return [
            'from_token_amount' => $from,
            'to_token_amount' => $to,
            'min_to_token_amount' => $min,
            'quote' => $q,
            'tx_data' => $tx,
        ];
    }

    /**
     * Conservative USDC amount for Centiiv order sizing (min out after Panora slippage).
     * Exact-out quotes often omit to/min in the quote row — pass $fallbackUsdc from sizing (toTokenAmount request).
     */
    public static function centiivUsdcAmountFromQuote(array $parsed, ?float $fallbackUsdc = null): float
    {
        $min = $parsed['min_to_token_amount'] ?? null;
        if (is_float($min) && $min > 0) {
            return $min;
        }
        $to = $parsed['to_token_amount'] ?? null;
        if (is_float($to) && $to > 0) {
            return $to;
        }
        if ($fallbackUsdc !== null && $fallbackUsdc > 0) {
            return $fallbackUsdc;
        }

        return 0.0;
    }

    /**
     * USDC to send to 1Click after aptos-service Panora swap (exact-out often omits amounts in quote row).
     *
     * @param array<string, mixed> $aptosPanoraBlock panora key from /transactions/panora-swap JSON
     */
    public static function usdcOutFromAptosPanoraSwapResponse(array $aptosPanoraBlock, ?float $requestedToUsdc = null): float
    {
        $min = self::parseQuoteTokenAmount($aptosPanoraBlock['min_to_token_amount'] ?? null);
        if ($min !== null && $min > 0) {
            return $min;
        }
        $to = self::parseQuoteTokenAmount($aptosPanoraBlock['to_token_amount'] ?? null);
        if ($to !== null && $to > 0) {
            return $to;
        }
        if ($requestedToUsdc !== null && $requestedToUsdc > 0) {
            return $requestedToUsdc;
        }

        return 0.0;
    }

    /**
     * @param mixed $raw
     */
    private static function parseQuoteTokenAmount($raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            $f = (float) $raw;

            return $f > 0 ? $f : null;
        }
        if (is_string($raw) && is_numeric($raw)) {
            $f = (float) $raw;

            return $f > 0 ? $f : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function postJson(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        $maxAttempts = max(1, (int) ($_ENV['SENDRA_PANORA_QUOTE_RETRIES'] ?? getenv('SENDRA_PANORA_QUOTE_RETRIES') ?: 3));
        $last = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            if ($ch === false) {
                return $this->err('curl_init failed', 0);
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'x-api-key: ' . $this->apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 45,
            ]);
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_errno($ch) ? curl_error($ch) : null;
            curl_close($ch);
            if ($curlErr !== null) {
                $last = $this->err('Panora request failed: ' . $curlErr, $http);
            } else {
                $data = json_decode((string) $raw, true);
                if (!is_array($data)) {
                    $last = [
                        'success' => false,
                        'http_code' => $http,
                        'data' => [],
                        'raw' => is_string($raw) ? $raw : null,
                        'error' => 'Invalid JSON from Panora',
                    ];
                } elseif ($http < 200 || $http >= 300) {
                    $msg = isset($data['message']) && is_string($data['message']) ? $data['message'] : (isset($data['error']) && is_string($data['error']) ? $data['error'] : 'Panora HTTP ' . $http);
                    $last = [
                        'success' => false,
                        'http_code' => $http,
                        'data' => $data,
                        'raw' => is_string($raw) ? $raw : null,
                        'error' => $msg,
                    ];
                } else {
                    return [
                        'success' => true,
                        'http_code' => $http,
                        'data' => $data,
                        'raw' => is_string($raw) ? $raw : null,
                        'error' => null,
                    ];
                }
            }

            $retryable = in_array($last['http_code'], [429, 503, 502, 504], true);
            if (!$retryable || $attempt >= $maxAttempts) {
                break;
            }
            $delayMs = min(8000, 1200 * (2 ** ($attempt - 1)));
            usleep($delayMs * 1000);
        }

        return $last ?? $this->err('Panora request failed', 0);
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function err(string $message, int $http): array
    {
        return [
            'success' => false,
            'http_code' => $http,
            'data' => [],
            'raw' => null,
            'error' => $message,
        ];
    }
}
