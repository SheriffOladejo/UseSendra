<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sendra Aptos only — Centiiv conversion API (quotes, banks, orders).
 *
 * Standalone from logistics Centiiv Direct Pay ({@see CentiivDirectPayService}) and
 * from {@see CentiivWebhook.php}. Uses env prefix SENDRA_CENTIIV_* only.
 *
 * @see https://c-vmch.readme.io/reference/createorder
 * @see https://c-vmch.readme.io/reference/getbanks (supported banks; bank codes are 6 digits)
 */
final class SendraCentiivRampService
{
    /** Centiiv/VA provider still returns legacy name; show correct institution to users. */
    private const CENTIIV_VA_BANK_DISPLAY_OVERRIDES = [
        'amucha mfb' => 'Nombank Microfinance Bank',
        'amucha' => 'Nombank Microfinance Bank',
    ];

    private string $token;

    private string $baseUrl;

    private ?string $platformHeader;

    public function __construct(?string $token = null, ?string $baseUrl = null, ?string $platformHeader = null)
    {
        if ($token !== null) {
            $this->token = trim($token);
        } else {
            $raw = $_ENV['SENDRA_CENTIIV_JWT'] ?? getenv('SENDRA_CENTIIV_JWT');
            if ($raw === false || $raw === null || $raw === '') {
                $raw = $_ENV['SENDRA_CENTIIV_API_KEY'] ?? getenv('SENDRA_CENTIIV_API_KEY');
            }
            $this->token = trim((string) ($raw ?: ''));
        }

        $rawBase = $baseUrl ?? (string) ($_ENV['SENDRA_CENTIIV_BASE_URL'] ?? getenv('SENDRA_CENTIIV_BASE_URL') ?: 'https://api.centiiv.io');
        $this->baseUrl = self::normalizeAggregatorBaseUrl($rawBase);

        if ($platformHeader !== null) {
            $this->platformHeader = $platformHeader !== '' ? $platformHeader : null;
        } else {
            $p = $_ENV['SENDRA_CENTIIV_PLATFORM'] ?? getenv('SENDRA_CENTIIV_PLATFORM');
            $this->platformHeader = is_string($p) && $p !== '' ? $p : null;
        }
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * Client reference stored on the Centiiv order so it can flow to the bank/Nomba narration
     * (Opay "Remark" second line) and match {@see SendraCentiivPayoutReceiptFactory} display_reference.
     */
    public static function generatePayoutReference(): string
    {
        return 'SR-' . gmdate('ymd') . '-' . substr(bin2hex(random_bytes(8)), 0, 12);
    }

    /**
     * Attach payout reference to conversion order metadata (trackingId for bank narration).
     * Centiiv /public/order rejects a top-level `reference` field — use metadata only.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function enrichOfframpOrderBody(array $body, ?string $payoutReference = null): array
    {
        $toAsset = strtoupper((string) ($body['toAsset'] ?? $body['to_asset'] ?? ''));
        if (!in_array($toAsset, ['NGN', 'XOF', 'XAF', 'GHS', 'KES'], true)) {
            return $body;
        }

        $meta = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : [];
        $ref = trim((string) ($payoutReference ?? ''));
        if ($ref === '') {
            $ref = trim((string) ($meta['sendra_payout_reference'] ?? $meta['trackingId'] ?? ''));
        }
        if ($ref === '') {
            $ref = self::generatePayoutReference();
        }

        $meta['sendra_payout_reference'] = $ref;
        if (empty($meta['trackingId'])) {
            $meta['trackingId'] = $ref;
        }
        $body['metadata'] = $meta;

        unset($body['reference']);

        return $body;
    }

    /**
     * Format Centiiv createRequest body for POST /requests (polymorphic beneficiary.destination for XOF/XAF/KES/GHS/UGX, etc.).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function formatOfframpBodyForRequestsEndpoint(array $body): array
    {
        $toAsset = strtoupper((string) ($body['toAsset'] ?? $body['to_asset'] ?? ''));
        if (!isset($body['network']) || trim((string) $body['network']) === '') {
            $body['network'] = SendraOfframpBridge::centiivNetwork();
        }

        $beneficiary = isset($body['beneficiary']) && is_array($body['beneficiary']) ? $body['beneficiary'] : [];
        $externalId = (string) ($beneficiary['externalId'] ?? '');

        $meta = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : [];
        $userPhone = (string) ($meta['user_phone'] ?? '');

        // Sender details for Mobile Money payouts (required by Centiiv for XOF routes like Togo/Benin)
        $senderName = 'Sendra User';
        if (!empty($meta['sender_name']) && is_string($meta['sender_name']) && trim($meta['sender_name']) !== '') {
            $senderName = trim($meta['sender_name']);
        } elseif ($userPhone !== '') {
            $senderName = 'Sendra User (' . $userPhone . ')';
        }

        $senderCountry = 'NG';
        if (str_starts_with($userPhone, '228')) {
            $senderCountry = 'TG';
        } elseif (str_starts_with($userPhone, '229')) {
            $senderCountry = 'BJ';
        } elseif (str_starts_with($userPhone, '225')) {
            $senderCountry = 'CI';
        } elseif (str_starts_with($userPhone, '221')) {
            $senderCountry = 'SN';
        } elseif (str_starts_with($userPhone, '254')) {
            $senderCountry = 'KE';
        } elseif (str_starts_with($userPhone, '233')) {
            $senderCountry = 'GH';
        }

        if (isset($beneficiary['destination']) && is_array($beneficiary['destination'])) {
            if ($toAsset === 'XOF' && empty($beneficiary['destination']['sender'])) {
                $body['beneficiary']['destination']['sender'] = [
                    'accountName' => $senderName,
                    'country' => $senderCountry,
                ];
            }

            return $body;
        }

        $ba = isset($beneficiary['bankAccount']) && is_array($beneficiary['bankAccount']) ? $beneficiary['bankAccount'] : null;
        if ($ba !== null) {
            $acctNum = trim((string) ($ba['accountNumber'] ?? ''));
            $acctName = trim((string) ($ba['accountName'] ?? ''));
            $bankCode = strtoupper(trim((string) ($ba['bankCode'] ?? '')));

            if (in_array($toAsset, ['XOF', 'XAF', 'KES', 'GHS', 'UGX', 'CDF', 'ETB'], true)) {
                $dest = [
                    'type' => 'MOBILEMONEY',
                    'accountNumber' => $acctNum,
                    'accountName' => $acctName !== '' ? $acctName : ('MoMo User (' . $acctNum . ')'),
                ];

                if ($toAsset === 'XOF') {
                    $country = 'BJ';
                    if (str_starts_with($acctNum, '225')) {
                        $country = 'CI';
                    } elseif (str_starts_with($acctNum, '221')) {
                        $country = 'SN';
                    } elseif (str_starts_with($acctNum, '228')) {
                        $country = 'TG';
                    } elseif (str_starts_with($acctNum, '226')) {
                        $country = 'BF';
                    } elseif (str_starts_with($acctNum, '223')) {
                        $country = 'ML';
                    } elseif (str_starts_with($acctNum, '227')) {
                        $country = 'NE';
                    }
                    $dest['country'] = $country;
                    if ($country === 'TG') {
                        // Centiiv API routes all Togo (+228) mobile money payouts via TMONEY.
                        $dest['network'] = 'TMONEY';
                    } else {
                        $dest['network'] = in_array($bankCode, ['MTN', 'ORANGE', 'MOOV', 'WAVE', 'TMONEY', 'FREEMONEY'], true) ? $bankCode : 'MTN';
                    }
                    $dest['sender'] = [
                        'accountName' => $senderName,
                        'country' => $senderCountry,
                    ];
                } elseif ($toAsset === 'XAF') {
                    $country = 'CM';
                    if (str_starts_with($acctNum, '241')) {
                        $country = 'GA';
                    } elseif (str_starts_with($acctNum, '242')) {
                        $country = 'CG';
                    } elseif (str_starts_with($acctNum, '236')) {
                        $country = 'CF';
                    } elseif (str_starts_with($acctNum, '235')) {
                        $country = 'TD';
                    }
                    $dest['country'] = $country;
                    $dest['network'] = in_array($bankCode, ['MTN', 'ORANGE', 'AIRTEL'], true) ? $bankCode : 'MTN';
                } elseif ($toAsset === 'KES') {
                    $dest['network'] = 'MPESA';
                } elseif ($toAsset === 'GHS') {
                    $dest['network'] = in_array($bankCode, ['MTN', 'VODAFONE', 'AIRTELTIGO'], true) ? $bankCode : 'MTN';
                } elseif ($toAsset === 'UGX') {
                    $dest['network'] = in_array($bankCode, ['MTN', 'AIRTEL'], true) ? $bankCode : 'AIRTEL';
                }

                $newBeneficiary = [];
                if ($externalId !== '') {
                    $newBeneficiary['externalId'] = $externalId;
                }
                $newBeneficiary['destination'] = $dest;
                $body['beneficiary'] = $newBeneficiary;
            }
        }

        return $body;
    }

    /**
     * Off-ramp / NGN payout create. Defaults to POST /requests (Centiiv's preferred path).
     * Set SENDRA_CENTIIV_SELL_USE_REQUESTS=0 to fall back to legacy POST /public/order.
     * Covers WhatsApp sell, “send ₦X” quick sell, and logistics rider withdraws.
     *
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function createConversionOrder(array $body): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        $hints = $this->mergeRoutingHints($body);
        $preMeta = is_array($hints['metadata'] ?? null) ? $hints['metadata'] : [];
        $preRef = trim((string) ($preMeta['sendra_payout_reference'] ?? $preMeta['trackingId'] ?? ''));
        $merged = self::enrichOfframpOrderBody($hints, $preRef !== '' ? $preRef : null);
        $meta = is_array($merged['metadata'] ?? null) ? $merged['metadata'] : [];
        $waPhone = trim((string) ($meta['user_phone'] ?? ''));
        $isSendraWa = ($meta['source'] ?? '') === 'sendra_whatsapp';
        $defaultRetries = $isSendraWa
            ? (int) ($_ENV['SENDRA_CENTIIV_ORDER_RETRIES_SELL'] ?? getenv('SENDRA_CENTIIV_ORDER_RETRIES_SELL') ?: 1)
            : (int) ($_ENV['SENDRA_CENTIIV_ORDER_RETRIES'] ?? getenv('SENDRA_CENTIIV_ORDER_RETRIES') ?: 3);
        $maxAttempts = max(1, min(4, $defaultRetries));
        $timeout = max(30, min(120, (int) ($_ENV['SENDRA_CENTIIV_ORDER_TIMEOUT'] ?? getenv('SENDRA_CENTIIV_ORDER_TIMEOUT') ?: 60)));
        $toAsset = strtoupper((string) ($merged['toAsset'] ?? $merged['to_asset'] ?? ''));
        $useRequests = self::sellUsesRequestsEndpoint();
        $createPath = $useRequests ? '/requests' : '/public/order';
        // POST /requests rejects webhookUrl (same as buy); rely on Centiiv dashboard webhooks.
        if ($useRequests) {
            $merged = self::formatOfframpBodyForRequestsEndpoint($merged);
            unset($merged['webhookUrl']);
        }
        if ($isSendraWa && $waPhone !== '') {
            $reuseWindow = max(60, (int) ($_ENV['SENDRA_CENTIIV_ORDER_REUSE_SECONDS'] ?? getenv('SENDRA_CENTIIV_ORDER_REUSE_SECONDS') ?: 300));
            $cached = self::pullRecentOfframpOrderCreatedForPhone($waPhone, $reuseWindow);
            if ($cached !== null) {
                // Only reuse a cached order when it is the SAME transaction (asset + amount +
                // beneficiary). A phone-only cache from a prior, differently-sized attempt would
                // otherwise process a larger deposit against a smaller order and strand the excess
                // in the temporary wallet (see 50 USDT vs 43.84 order incident).
                if (self::cachedOrderMatchesRequest($cached, $merged)) {
                    error_log('Sendra Centiiv: reusing amount-matched cached order.created before POST ' . $createPath . ' for ' . $waPhone);

                    return [
                        'success' => true,
                        'http_code' => 200,
                        'data' => $cached,
                        'raw' => null,
                        'error' => null,
                    ];
                }
                error_log(sprintf(
                    'Sendra Centiiv: discarding stale cached order.created for %s (cached amount %s %s != requested %s %s); creating fresh order',
                    $waPhone,
                    (string) ($cached['amount'] ?? '?'),
                    (string) ($cached['fromAsset'] ?? '?'),
                    (string) ($merged['amount'] ?? '?'),
                    (string) ($merged['fromAsset'] ?? '?')
                ));
            }
        }

        $last = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $last = $this->postJson($createPath, $merged, $timeout);
            if (!empty($last['success'])) {
                return $last;
            }
            if ($this->isRetryableCentiivTransportError($last) && $waPhone !== '') {
                usleep(2500000);
                $reuseWindow = max(60, (int) ($_ENV['SENDRA_CENTIIV_ORDER_REUSE_SECONDS'] ?? getenv('SENDRA_CENTIIV_ORDER_REUSE_SECONDS') ?: 300));
                $cached = self::pullRecentOfframpOrderCreatedForPhone($waPhone, $reuseWindow);
                if ($cached !== null && self::cachedOrderMatchesRequest($cached, $merged)) {
                    error_log('Sendra Centiiv: using amount-matched order.created webhook after POST ' . $createPath . ' timeout for ' . $waPhone);

                    return [
                        'success' => true,
                        'http_code' => 200,
                        'data' => $cached,
                        'raw' => null,
                        'error' => null,
                    ];
                }
                if ($cached !== null) {
                    error_log('Sendra Centiiv: ignoring non-matching cached order.created after POST timeout for ' . $waPhone . '; will retry create');
                }
            }
            if (!$this->isRetryableCentiivTransportError($last) || $attempt >= $maxAttempts) {
                return $last;
            }
            error_log(
                'Sendra Centiiv ramp POST ' . $createPath . ' transport retry '
                . $attempt . '/' . $maxAttempts . ': ' . ($last['error'] ?? 'unknown')
            );
            usleep(1500000 * $attempt);
        }

        return $last ?? $this->notConfigured();
    }

    /**
     * Sell/off-ramp create uses POST /requests by default. Set SENDRA_CENTIIV_SELL_USE_REQUESTS=0
     * to fall back to legacy POST /public/order.
     */
    public static function sellUsesRequestsEndpoint(): bool
    {
        $raw = $_ENV['SENDRA_CENTIIV_SELL_USE_REQUESTS'] ?? getenv('SENDRA_CENTIIV_SELL_USE_REQUESTS');
        if ($raw === false || $raw === null || (is_string($raw) && trim($raw) === '')) {
            $flag = '1';
        } else {
            $flag = strtolower(trim((string) $raw));
        }

        return !in_array($flag, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * NGN on-ramp with Stellar destination + memo (1Click). Uses POST /requests — accepts destinationMemo*;
     * POST /public/order rejects those fields on many keys.
     *
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function createOnrampRequest(array $body): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        $merged = $this->mergeRoutingHints($body);
        $meta = is_array($merged['metadata'] ?? null) ? $merged['metadata'] : [];
        $waPhone = trim((string) ($meta['user_phone'] ?? ''));
        $maxAttempts = max(1, min(4, (int) ($_ENV['SENDRA_CENTIIV_ORDER_RETRIES'] ?? getenv('SENDRA_CENTIIV_ORDER_RETRIES') ?: 3)));
        $timeout = max(30, min(120, (int) ($_ENV['SENDRA_CENTIIV_ORDER_TIMEOUT'] ?? getenv('SENDRA_CENTIIV_ORDER_TIMEOUT') ?: 60)));

        $last = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $last = $this->postJson('/requests', $merged, $timeout);
            if (!empty($last['success'])) {
                return $last;
            }
            if ($this->isRetryableCentiivTransportError($last) && $waPhone !== '') {
                usleep(2500000);
                $cached = self::pullRecentOnrampOrderCreatedForPhone($waPhone);
                if ($cached !== null) {
                    error_log('Sendra Centiiv: using order.created webhook after POST /requests timeout for ' . $waPhone);

                    return [
                        'success' => true,
                        'http_code' => 200,
                        'data' => $cached,
                        'raw' => null,
                        'error' => null,
                    ];
                }
            }
            if (!$this->isRetryableCentiivTransportError($last) || $attempt >= $maxAttempts) {
                return $last;
            }
            error_log(
                'Sendra Centiiv ramp POST /requests transport retry '
                . $attempt . '/' . $maxAttempts . ': ' . ($last['error'] ?? 'unknown')
            );
            usleep(1500000 * $attempt);
        }

        return $last ?? $this->notConfigured();
    }

    /**
     * Centiiv often creates the order before HTTP responds; cache order.created webhook for timeout recovery.
     *
     * @param array<string, mixed> $event full webhook envelope
     */
    public static function cacheOfframpOrderCreatedFromWebhook(array $event): void
    {
        $data = $event['data'] ?? null;
        if (!is_array($data)) {
            return;
        }
        if (strtoupper((string) ($data['type'] ?? '')) !== 'OFFRAMP') {
            return;
        }
        $phone = null;
        $desc = $data['description'] ?? null;
        // Native Stellar sells use "Sendra WhatsApp Stellar sell {phone}".
        if (is_string($desc) && preg_match('/\bSendra\s+WhatsApp\s+(?:[A-Za-z]+\s+)?sell\s+(\d{10,20})\b/i', $desc, $m) === 1) {
            $phone = $m[1];
        }
        if ($phone === null || $phone === '') {
            return;
        }
        $dir = dirname(__DIR__, 2) . '/var/sendra_centiiv_pending_orders';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = [
            'cached_at' => time(),
            'order' => $data,
        ];
        @file_put_contents($dir . '/' . preg_replace('/\D+/', '', $phone) . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Cache ONRAMP order.created for buy timeout recovery and webhook bank notify.
     *
     * @param array<string, mixed> $event full webhook envelope
     */
    public static function cacheOnrampOrderCreatedFromWebhook(array $event): void
    {
        $data = $event['data'] ?? null;
        if (!is_array($data)) {
            return;
        }
        if (strtoupper((string) ($data['type'] ?? '')) !== 'ONRAMP') {
            return;
        }
        $phone = null;
        $desc = $data['description'] ?? null;
        if (is_string($desc) && preg_match('/\bSendra\s+WhatsApp\s+buy\s+(\d{10,20})\b/i', $desc, $m) === 1) {
            $phone = $m[1];
        }
        if ($phone === null || $phone === '') {
            return;
        }
        $dir = dirname(__DIR__, 2) . '/var/sendra_centiiv_pending_orders';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = [
            'cached_at' => time(),
            'order' => $data,
            'kind' => 'onramp',
        ];
        @file_put_contents($dir . '/' . preg_replace('/\D+/', '', $phone) . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return ?array<string, mixed> Centiiv order payload (same shape as create-order response data)
     */
    /**
     * Read cached OFFRAMP order.created without deleting (cooldown / duplicate guards).
     *
     * @return ?array<string, mixed>
     */
    public static function peekRecentOfframpOrderCreatedForPhone(string $waPhone, int $maxAgeSeconds = 180): ?array
    {
        $phone = preg_replace('/\D+/', '', $waPhone);
        if ($phone === '') {
            return null;
        }
        $path = dirname(__DIR__, 2) . '/var/sendra_centiiv_pending_orders/' . $phone . '.json';
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['order'] ?? null)) {
            return null;
        }
        if (($decoded['kind'] ?? '') === 'onramp'
            || strtoupper((string) ($decoded['order']['type'] ?? '')) === 'ONRAMP') {
            return null;
        }
        $cachedAt = (int) ($decoded['cached_at'] ?? 0);
        if ($cachedAt <= 0 || time() - $cachedAt > $maxAgeSeconds) {
            return null;
        }

        return $decoded['order'];
    }

    public static function pullRecentOfframpOrderCreatedForPhone(string $waPhone, int $maxAgeSeconds = 180): ?array
    {
        $order = self::peekRecentOfframpOrderCreatedForPhone($waPhone, $maxAgeSeconds);
        if ($order === null) {
            return null;
        }
        $phone = preg_replace('/\D+/', '', $waPhone);
        $path = dirname(__DIR__, 2) . '/var/sendra_centiiv_pending_orders/' . $phone . '.json';
        @unlink($path);

        return $order;
    }

    /**
     * @return ?array<string, mixed> Centiiv ONRAMP order from a recent order.created webhook
     */
    public static function pullRecentOnrampOrderCreatedForPhone(string $waPhone, int $maxAgeSeconds = 180): ?array
    {
        $phone = preg_replace('/\D+/', '', $waPhone);
        if ($phone === '') {
            return null;
        }
        $path = dirname(__DIR__, 2) . '/var/sendra_centiiv_pending_orders/' . $phone . '.json';
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['order'] ?? null)) {
            return null;
        }
        if (($decoded['kind'] ?? '') !== 'onramp'
            && strtoupper((string) ($decoded['order']['type'] ?? '')) !== 'ONRAMP') {
            return null;
        }
        $cachedAt = (int) ($decoded['cached_at'] ?? 0);
        if ($cachedAt <= 0 || time() - $cachedAt > $maxAgeSeconds) {
            @unlink($path);

            return null;
        }
        @unlink($path);

        return $decoded['order'];
    }

    /**
     * Guard against reusing a stale cached order from a *different* transaction.
     *
     * A cached order.created (keyed only by phone) may only stand in for the order we are about
     * to / just tried to POST when it is unmistakably the SAME order: same asset pair, the same
     * amount (within a tight tolerance) and the same beneficiary account. Without this guard, a
     * leftover order from an earlier, differently-sized attempt would settle a larger deposit
     * against a smaller order and strand the excess in Centiiv's temporary wallet.
     *
     * @param array<string, mixed> $cached  order.created data
     * @param array<string, mixed> $request order body we intend to (or did) POST
     */
    public static function cachedOrderMatchesRequest(array $cached, array $request): bool
    {
        $cachedAmount = (float) ($cached['amount'] ?? 0);
        $requestAmount = (float) ($request['amount'] ?? 0);
        if ($cachedAmount <= 0 || $requestAmount <= 0) {
            return false;
        }
        // Reuse is only safe for a genuine retry of the SAME order POST, where Centiiv stored the
        // exact amount we sent. Different user attempts re-quote and produce different amounts (even
        // for the same USDT input), so the match must be near-exact — a loose tolerance would let a
        // 1.526 order settle a 1.506 deposit (under-fill → order stuck PENDING) or vice-versa.
        $tolerance = 0.0005;
        if (abs($cachedAmount - $requestAmount) > $tolerance) {
            return false;
        }

        $cachedFrom = strtoupper((string) ($cached['fromAsset'] ?? ''));
        $requestFrom = strtoupper((string) ($request['fromAsset'] ?? ''));
        if ($cachedFrom !== '' && $requestFrom !== '' && $cachedFrom !== $requestFrom) {
            return false;
        }
        $cachedTo = strtoupper((string) ($cached['toAsset'] ?? ''));
        $requestTo = strtoupper((string) ($request['toAsset'] ?? ''));
        if ($cachedTo !== '' && $requestTo !== '' && $cachedTo !== $requestTo) {
            return false;
        }

        $cachedAcct = self::extractBeneficiaryAccountNumber($cached);
        $requestAcct = self::extractBeneficiaryAccountNumber($request);
        if ($cachedAcct !== '' && $requestAcct !== '' && $cachedAcct !== $requestAcct) {
            return false;
        }

        return true;
    }

    /**
     * Pull the destination account number out of an order/request beneficiary block.
     *
     * @param array<string, mixed> $order
     */
    private static function extractBeneficiaryAccountNumber(array $order): string
    {
        $beneficiary = $order['beneficiary'] ?? null;
        if (!is_array($beneficiary)) {
            return '';
        }
        $bankAccount = is_array($beneficiary['bankAccount'] ?? null) ? $beneficiary['bankAccount'] : $beneficiary;
        foreach (['accountNumber', 'account_number', 'accountNo', 'account_no'] as $k) {
            if (!empty($bankAccount[$k]) && is_scalar($bankAccount[$k])) {
                return (string) preg_replace('/\D+/', '', (string) $bankAccount[$k]);
            }
        }

        return '';
    }

    /**
     * Live wallet FX board (buy/sell) from Centiiv Payments.
     *
     * GET https://api.live.centiiv.com/api/v1/wallet/rates
     * Optional filter: ?from=USDC&to=NGN
     *
     * This host is not the liquidity aggregator (api.centiiv.io). The board is public.
     *
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function getWalletRates(?string $from = null, ?string $to = null): array
    {
        $base = trim((string) ($_ENV['SENDRA_CENTIIV_RATES_URL'] ?? getenv('SENDRA_CENTIIV_RATES_URL') ?: 'https://api.live.centiiv.com/api/v1/wallet/rates'));
        $url = $base;
        $query = [];
        $from = strtoupper(trim((string) $from));
        $to = strtoupper(trim((string) $to));
        if ($from !== '') {
            $query['from'] = $from;
        }
        if ($to !== '') {
            $query['to'] = $to;
        }
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $this->getJsonAbsolute($url, false);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function getQuote(array $body): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        if (!isset($body['network']) || trim((string) $body['network']) === '') {
            $body['network'] = SendraOfframpBridge::centiivNetwork();
        }

        return $this->postJson('/public/quote', $this->mergeRoutingHints($body));
    }

    /**
     * Optional env-driven routing (see Centiiv create-order docs: targetNodeId).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function mergeRoutingHints(array $body): array
    {
        $node = trim((string) ($_ENV['SENDRA_CENTIIV_TARGET_NODE_ID'] ?? getenv('SENDRA_CENTIIV_TARGET_NODE_ID') ?: ''));
        if ($node !== '' && !isset($body['targetNodeId'])) {
            $body['targetNodeId'] = $node;
        }

        return $body;
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function verifyBankAccount(string $bankCode, string $accountNumber): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        return $this->postJson('/banking/verify-account', [
            'bankCode' => self::normalizeCentiivBankCode($bankCode),
            'accountNumber' => $accountNumber,
        ]);
    }

    /**
     * Centiiv expects a 6-digit bank code (NIP); short forms like "058" → "000058".
     * Use codes from {@see getBanks} / {@see findRawBankRowInBanksResponse} when possible.
     */
    public static function normalizeCentiivBankCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return $code;
        }
        $digits = preg_replace('/\D+/', '', $code) ?? '';
        if ($digits === '' || strlen($digits) > 20) {
            return $code;
        }
        if (strlen($digits) <= 6) {
            return str_pad($digits, 6, '0', STR_PAD_LEFT);
        }

        return substr($digits, -6);
    }

    /**
     * When {@see getBanks} fails or omits a code, map common NIP / wallet 6-digit codes to a display name
     * so receipts show e.g. "OPay" instead of "Bank code 100004". API lookup in
     * {@see SendraCentiivPayoutReceiptFactory} takes precedence when it succeeds.
     *
     * @var array<string, string>
     */
    private const FALLBACK_NIP_BANK_NAMES = [
        '050515' => 'Moniepoint MFB',
        '090405' => 'Moniepoint MFB',
        '100001' => 'FET',
        '100002' => 'Paga',
        '100003' => 'Parkway-ReadyCash',
        '100004' => 'OPay',
        '100005' => 'Cellulant',
        '100006' => 'eTranzact',
        '100007' => 'Stanbic IBTC @ease wallet',
        '100008' => 'Ecobank Xpress Account',
        '100009' => 'GTMobile',
        '100010' => 'TeasyMobile',
        '100011' => 'Mkudi',
        '100012' => 'VTNetworks',
        '100013' => 'AccessMobile',
        '100014' => 'FBNMobile',
        '100016' => 'FortisMobile',
        '100017' => 'Hedonmark',
        '100018' => 'ZenithMobile',
        '100019' => 'Fidelity Mobile',
        '100020' => 'MoneyBox',
        '100021' => 'Eartholeum',
        '100022' => 'GoMoney',
        '100023' => 'TagPay',
        '100025' => 'Zinternet Nigeria Limited',
        '100026' => 'One Finance',
        '100027' => 'Intellifin',
        '100029' => 'Innovectives Kesh',
        '100030' => 'EcoMobile',
        '100031' => 'FCMB Easy Account',
        '100032' => 'NowNow',
        '100033' => 'PalmPay',
        '100034' => 'Zenith Eazy Wallet',
        '100035' => 'M36',
        '100036' => 'Kegow (Chamsmobile)',
        '100039' => 'TitanPaystack',
        '100052' => 'Access Yello',
        '110001' => 'PayAttitude Online',
        '110002' => 'Flutterwave',
        '110003' => 'Interswitch',
        '110004' => 'First Apple Limited',
        '110005' => '3line Card Management Limited',
        '110006' => 'Paystack Payments',
        '110007' => 'TeamApt',
        '120003' => 'MTN MoMo PSB',
    ];

    public static function fallbackNipBankDisplayName(string $code): ?string
    {
        $n = self::normalizeCentiivBankCode($code);
        if ($n === '') {
            return null;
        }
        if (isset(self::FALLBACK_NIP_BANK_NAMES[$n])) {
            return self::FALLBACK_NIP_BANK_NAMES[$n];
        }
        $stripped = ltrim($n, '0');
        if ($stripped !== '' && isset(self::FALLBACK_NIP_BANK_NAMES[$stripped])) {
            return self::FALLBACK_NIP_BANK_NAMES[$stripped];
        }

        return null;
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function getBanks(): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        $cached = self::readBanksCache();
        if ($cached !== null) {
            return [
                'success' => true,
                'http_code' => 200,
                'data' => $cached,
                'raw' => null,
                'error' => null,
            ];
        }

        $fresh = $this->getJson('/banking/banks');
        if ($fresh['success'] && is_array($fresh['data'] ?? null)) {
            self::writeBanksCache($fresh['data']);
        }

        return $fresh;
    }

    private static function banksCachePath(): string
    {
        $p = $_ENV['SENDRA_CENTIIV_BANKS_CACHE_PATH'] ?? getenv('SENDRA_CENTIIV_BANKS_CACHE_PATH');
        if (is_string($p) && trim($p) !== '') {
            return trim($p);
        }

        return dirname(__DIR__, 2) . '/var/centiiv_banks_cache.json';
    }

    private static function banksCacheTtlSeconds(): int
    {
        $raw = $_ENV['SENDRA_CENTIIV_BANKS_CACHE_TTL'] ?? getenv('SENDRA_CENTIIV_BANKS_CACHE_TTL');
        if (is_string($raw) && trim($raw) !== '') {
            return max(60, (int) $raw);
        }

        return 900;
    }

    /**
     * @return ?array<string, mixed>
     */
    private static function readBanksCache(): ?array
    {
        $path = self::banksCachePath();
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
        if (!is_array($j) || !isset($j['data'], $j['cached_at'])) {
            return null;
        }
        $ts = strtotime((string) $j['cached_at']);
        if ($ts === false || (time() - $ts) > self::banksCacheTtlSeconds()) {
            return null;
        }
        $data = $j['data'];

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeBanksCache(array $data): void
    {
        $path = self::banksCachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = json_encode([
            'cached_at' => gmdate('c'),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return;
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        @rename($tmp, $path);
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function getRequest(string $id): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        return $this->getJson('/requests/' . rawurlencode($id));
    }

    /**
     * Find the raw bank row from GET /banking/banks JSON (preserves id, slug, etc. for create-order).
     *
     * @param array<string, mixed> $getBanksDecoded
     * @return array<string, mixed>|null
     */
    public static function findRawBankRowInBanksResponse(array $getBanksDecoded, string $code): ?array
    {
        $codeNorm = self::normalizeCentiivBankCode($code);
        $root = $getBanksDecoded['data'] ?? $getBanksDecoded['banks'] ?? $getBanksDecoded;
        if (!is_array($root)) {
            return null;
        }
        $list = isset($root[0]) && is_array($root[0]) ? $root : (isset($root['banks']) && is_array($root['banks']) ? $root['banks'] : []);
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $c = (string) ($row['code'] ?? $row['bankCode'] ?? $row['bank_code'] ?? '');
            if ($c === '' || $codeNorm === '') {
                continue;
            }
            $cNorm = self::normalizeCentiivBankCode($c);
            if ($cNorm === $codeNorm) {
                return $row;
            }
            if (ltrim($c, '0') === ltrim($codeNorm, '0')) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Finds bank row matching either bank code or bank name / alias (e.g. OPay, PalmPay, Kuda, GTBank).
     *
     * @param array<string, mixed> $getBanksDecoded
     * @return array<string, mixed>|null
     */
    public static function findBankRowByNameOrCode(array $getBanksDecoded, string $codeOrQuery): ?array
    {
        $query = strtolower(trim($codeOrQuery));
        if ($query === '') {
            return null;
        }

        // Try code match first
        $rowByCode = self::findRawBankRowInBanksResponse($getBanksDecoded, $query);
        if ($rowByCode !== null) {
            return $rowByCode;
        }

        // Search by name & alias
        $root = $getBanksDecoded['data'] ?? $getBanksDecoded['banks'] ?? $getBanksDecoded;
        if (!is_array($root)) {
            return null;
        }
        $list = isset($root[0]) && is_array($root[0]) ? $root : (isset($root['banks']) && is_array($root['banks']) ? $root['banks'] : []);

        $aliasMap = [
            'opay' => ['paycom', 'opay'],
            'palmpay' => ['palmpay'],
            'palm pay' => ['palmpay'],
            'moniepoint' => ['moniepoint', 'teamapt'],
            'monie' => ['moniepoint', 'teamapt'],
            'kuda' => ['kuda'],
            'gtb' => ['guaranty', 'gtbank', 'gtb'],
            'gtbank' => ['guaranty', 'gtbank', 'gtb'],
            'firstbank' => ['first bank', 'firstbank'],
            'uba' => ['united bank for africa', 'uba'],
            'zenith' => ['zenith'],
            'wema' => ['wema'],
            'access' => ['access bank', 'access'],
        ];

        $searchTerms = $aliasMap[$query] ?? [$query];

        foreach ($list as $b) {
            if (!is_array($b)) {
                continue;
            }
            $bName = strtolower((string) ($b['name'] ?? $b['bankName'] ?? ''));
            foreach ($searchTerms as $term) {
                if (str_contains($bName, $term)) {
                    return $b;
                }
            }
        }

        return null;
    }

    /**
     * Stable id for Centiiv create-order beneficiary.externalId (dashboard / support correlation).
     */
    public static function beneficiaryExternalIdFromWaPhone(string $waFrom): string
    {
        $s = preg_replace('/[^\d+]/', '', $waFrom);
        if ($s === '') {
            $s = preg_replace('/\s+/', '', $waFrom);
        }
        $s = trim($s);
        if ($s === '') {
            return 'sendra_wa_unknown';
        }
        if (strlen($s) > 40) {
            $s = substr($s, 0, 40);
        }

        return 'sendra_wa_' . $s;
    }

    /**
     * Centiiv create-order beneficiary: {@see https://api.centiiv.io} expects bankAccount + optional externalId.
     *
     * @param array<string, mixed>|null $rawBankRow from {@see findRawBankRowInBanksResponse}
     * @return array<string, mixed>
     */
    public static function buildOfframpBeneficiary(
        string $accountNumber,
        string $bankCode,
        string $accountName,
        string $bankDisplayName = '',
        ?array $rawBankRow = null,
        ?string $externalId = null
    ): array {
        $bankAccount = [
            'bankCode' => self::normalizeCentiivBankCode($bankCode),
            'accountNumber' => $accountNumber,
            'accountName' => $accountName,
        ];
        if (is_array($rawBankRow)) {
            foreach (['bankId', 'bank_id', 'id', 'uuid'] as $k) {
                if (empty($rawBankRow[$k]) || !is_scalar($rawBankRow[$k])) {
                    continue;
                }
                $outKey = $k === 'bank_id' ? 'bankId' : $k;
                $bankAccount[$outKey] = $rawBankRow[$k];
            }
        }

        $out = ['bankAccount' => $bankAccount];
        if ($externalId !== null && $externalId !== '') {
            $out['externalId'] = $externalId;
        }

        return $out;
    }

    /**
     * Merge /banking/verify-account fields into beneficiary.bankAccount.
     *
     * @param array<string, mixed>|null $verifyApiEnvelope decoded JSON from verify-account
     * @return array<string, mixed>
     */
    public static function buildOfframpBeneficiaryWithVerify(
        string $accountNumber,
        string $bankCode,
        string $accountName,
        string $bankDisplayName,
        ?array $rawBankRow,
        ?array $verifyApiEnvelope,
        ?string $externalId = null
    ): array {
        $b = self::buildOfframpBeneficiary($accountNumber, $bankCode, $accountName, $bankDisplayName, $rawBankRow, $externalId);
        if (!is_array($verifyApiEnvelope)) {
            return $b;
        }
        $root = $verifyApiEnvelope['data'] ?? $verifyApiEnvelope;
        if (!is_array($root)) {
            return $b;
        }

        $ba = $b['bankAccount'];

        if (isset($root['bankAccount']) && is_array($root['bankAccount'])) {
            foreach ($root['bankAccount'] as $k => $v) {
                if ($v === null || $v === '' || !is_scalar($v)) {
                    continue;
                }
                $ba[$k] = $v;
            }
        }

        if (isset($root['bank']) && is_array($root['bank'])) {
            $bk = $root['bank'];
            $bc = (string) ($bk['bankCode'] ?? $bk['code'] ?? $bk['bank_code'] ?? '');
            if ($bc !== '') {
                $ba['bankCode'] = $bc;
            }
            foreach (['bankId', 'id', 'uuid'] as $k) {
                if (!empty($bk[$k]) && is_scalar($bk[$k])) {
                    $ba[$k] = $bk[$k];
                }
            }
        }

        foreach (['bankId', 'bank_id'] as $bk) {
            if (!empty($root[$bk]) && is_scalar($root[$bk])) {
                $ba[$bk === 'bank_id' ? 'bankId' : $bk] = $root[$bk];
            }
        }

        $an = $root['accountName'] ?? $root['account_name'] ?? null;
        if (is_string($an) && $an !== '') {
            $ba['accountName'] = $an;
        }
        $num = $root['accountNumber'] ?? $root['account_number'] ?? null;
        if (is_string($num) && $num !== '') {
            $ba['accountNumber'] = $num;
        }

        $ba['bankCode'] = (string) ($ba['bankCode'] ?? '') !== '' ? (string) $ba['bankCode'] : $bankCode;
        $ba['accountNumber'] = (string) ($ba['accountNumber'] ?? '') !== '' ? (string) $ba['accountNumber'] : $accountNumber;
        $ba['accountName'] = (string) ($ba['accountName'] ?? '') !== '' ? (string) $ba['accountName'] : $accountName;
        $ba['bankCode'] = self::normalizeCentiivBankCode($ba['bankCode']);

        $b['bankAccount'] = $ba;

        // Do not replace the WhatsApp initiator id with Centiiv's verify-account externalId.
        // Centiiv may return a prior customer's id when the same NUBAN was used before (wrong receipt routing).
        $ex = $root['externalId'] ?? null;
        if (is_string($ex) && $ex !== '' && $externalId !== null && $externalId !== '') {
            if ($ex === $externalId) {
                $b['externalId'] = $ex;
            } elseif (!preg_match('/^sendra_wa_\d{10,20}$/', $ex)) {
                $b['externalId'] = $ex;
            }
        }

        return $b;
    }

    /**
     * Route off-ramp NGN to Sendra's Centiiv-managed account, then exact-amount disburse to the user.
     * Enabled when SENDRA_CENTIIV_INTERMEDIARY_NGN=1 and account env vars are set.
     */
    public static function isIntermediaryNgnPayoutEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['SENDRA_CENTIIV_INTERMEDIARY_NGN'] ?? getenv('SENDRA_CENTIIV_INTERMEDIARY_NGN') ?: '')));
        if (!in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        return self::sendraIntermediaryNgnAccount() !== null;
    }

    /**
     * @return array{account_number: string, bank_code: string, account_name: string, bank_name: string}|null
     */
    public static function sendraIntermediaryNgnAccount(): ?array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $acct = trim((string) ($_ENV['SENDRA_CENTIIV_NGN_ACCOUNT_NUMBER'] ?? getenv('SENDRA_CENTIIV_NGN_ACCOUNT_NUMBER') ?: ''));
        $code = trim((string) ($_ENV['SENDRA_CENTIIV_NGN_BANK_CODE'] ?? getenv('SENDRA_CENTIIV_NGN_BANK_CODE') ?: ''));
        $name = trim((string) ($_ENV['SENDRA_CENTIIV_NGN_ACCOUNT_NAME'] ?? getenv('SENDRA_CENTIIV_NGN_ACCOUNT_NAME') ?: 'Sendra / Centiiv'));
        $bank = trim((string) ($_ENV['SENDRA_CENTIIV_NGN_BANK_NAME'] ?? getenv('SENDRA_CENTIIV_NGN_BANK_NAME') ?: ''));

        if ($acct === '' || strlen($acct) !== 10 || $code === '') {
            $cached = false;

            return null;
        }

        $cached = [
            'account_number' => $acct,
            'bank_code' => self::normalizeCentiivBankCode($code),
            'account_name' => $name !== '' ? $name : 'Sendra / Centiiv',
            'bank_name' => $bank !== '' ? $bank : 'Centiiv',
        ];

        return $cached;
    }

    /**
     * Swap Centiiv off-ramp beneficiary to Sendra's NGN account; store user bank + requested amount in metadata.
     *
     * @param array<string, mixed> $body createConversionOrder body (mutated in place)
     * @return array<string, mixed>
     */
    public static function applyIntermediaryNgnOfframpBody(
        array $body,
        string $userAccountNumber,
        string $userBankCode,
        string $userAccountName,
        string $userBankDisplay,
        ?array $rawBankRow,
        float $requestedNgn
    ): array {
        $toAsset = strtoupper((string) ($body['toAsset'] ?? $body['to_asset'] ?? 'NGN'));
        if ($toAsset !== 'NGN' || !self::isIntermediaryNgnPayoutEnabled()) {
            return $body;
        }

        $sendraAcct = self::sendraIntermediaryNgnAccount();
        if ($sendraAcct === null) {
            return $body;
        }

        $meta = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : [];
        $meta['sendra_intermediary_payout'] = '1';
        $meta['sendra_requested_ngn'] = (string) round(max(0, $requestedNgn), 2);
        $meta['sendra_user_account_number'] = $userAccountNumber;
        $meta['sendra_user_bank_code'] = self::normalizeCentiivBankCode($userBankCode);
        $meta['sendra_user_account_name'] = $userAccountName;
        $meta['sendra_user_bank_name'] = $userBankDisplay;
        if ($rawBankRow !== null) {
            $meta['sendra_user_bank_raw'] = json_encode($rawBankRow, JSON_UNESCAPED_UNICODE);
        }
        $body['metadata'] = $meta;

        $body['beneficiary'] = self::buildOfframpBeneficiary(
            $sendraAcct['account_number'],
            $sendraAcct['bank_code'],
            $sendraAcct['account_name'],
            $sendraAcct['bank_name'],
            null,
            'sendra_intermediary_ngn'
        );

        return $body;
    }

    /**
     * Bank payout amount for intermediary mode from pending / sell_ctx (crypto-amount sells have target_receive_ngn=0).
     *
     * @param array<string, mixed> $pm pending human_description
     * @param array<string, mixed> $sellCtx
     */
    public static function resolveIntermediaryRequestedNgnFromPendingMeta(array $pm, array $sellCtx = []): float
    {
        $fiat = strtoupper((string) ($pm['fiat'] ?? $sellCtx['fiat'] ?? 'NGN'));
        if ($fiat !== 'NGN') {
            return 0.0;
        }

        // A user-accepted rate re-confirmation is the amount the user explicitly agreed to receive
        // after the rate moved. It must override the original (now stale) quote everywhere downstream
        // — especially the bank disburse — otherwise we try to pay the old, higher amount the pool
        // can no longer cover.
        foreach ([$pm, $sellCtx] as $src) {
            if (!empty($src['reconfirm_accepted'])
                && isset($src['rate_reconfirm_ngn'])
                && is_numeric($src['rate_reconfirm_ngn'])
                && (float) $src['rate_reconfirm_ngn'] > 0) {
                return round((float) $src['rate_reconfirm_ngn'], 2);
            }
        }

        $candidates = [
            $pm['target_receive_ngn'] ?? null,
            $sellCtx['target_receive_ngn'] ?? null,
            $sellCtx['sendra_resolved_requested_ngn'] ?? null,
            $pm['sendra_resolved_requested_ngn'] ?? null,
            $pm['sendra_quoted_receive_ngn'] ?? null,
            $sellCtx['sendra_quoted_receive_ngn'] ?? null,
        ];
        foreach ($candidates as $v) {
            if ($v !== null && is_numeric($v) && (float) $v > 0) {
                return round((float) $v, 2);
            }
        }
        foreach ([$pm['receive_ngn_display'] ?? null, $sellCtx['receive_ngn_display'] ?? null] as $display) {
            if (!is_string($display) && !is_numeric($display)) {
                continue;
            }
            $parsed = (float) preg_replace('/[^\d.]/', '', (string) $display);

            return $parsed > 0 ? round($parsed, 2) : 0.0;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $pm
     * @param array<string, mixed> $sellCtx
     * @return array{account_number: string, bank_code: string, account_name: string, bank_name: string}
     */
    public static function resolveIntermediaryUserBankFromPendingMeta(array $pm, array $sellCtx = []): array
    {
        return [
            'account_number' => trim((string) ($pm['account_number'] ?? $sellCtx['account_number'] ?? '')),
            'bank_code' => trim((string) ($pm['bank_code'] ?? $sellCtx['bank_code'] ?? '')),
            'account_name' => trim((string) ($pm['account_name'] ?? $sellCtx['account_name'] ?? '')),
            'bank_name' => trim((string) ($pm['bank_name'] ?? $sellCtx['bank_name'] ?? '')),
        ];
    }

    /**
     * Disburse exact NGN from Sendra's Centiiv account to a user's bank (post off-ramp conversion).
     *
     * Banking disburse lives on Direct Pay (api.centiiv.com). The liquidity aggregator
     * (api.centiiv.io / pk_live) does not expose POST /banking/disburse (returns 404).
     *
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function disburseNgnToBank(
        string $accountNumber,
        string $bankCode,
        float $amountNgn,
        string $trackingId,
        string $description
    ): array {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        $amountKobo = (int) round(max(0, $amountNgn) * 100);
        if ($amountKobo < 100) {
            return [
                'success' => false,
                'http_code' => 0,
                'data' => [],
                'raw' => null,
                'error' => 'Disburse amount too small',
            ];
        }

        $payload = [
            'disbursements' => [[
                'trackingId' => $trackingId,
                'amount' => $amountKobo,
                'currency' => 'NGN',
                'recipientAccountNumber' => $accountNumber,
                'recipientBankCode' => self::normalizeCentiivBankCode($bankCode),
                'description' => $description,
            ]],
        ];

        $requestSource = trim((string) ($_ENV['SENDRA_CENTIIV_DISBURSE_REQUEST_SOURCE'] ?? getenv('SENDRA_CENTIIV_DISBURSE_REQUEST_SOURCE') ?: 'sendra_whatsapp'));

        // Prefer Direct Pay — this is the working banking API.
        $direct = $this->disburseViaDirectPay($payload, $requestSource);
        if ($direct['success']) {
            return $direct;
        }

        $cntJwt = trim((string) ($_ENV['CENTIIV_JWT'] ?? getenv('CENTIIV_JWT') ?: ''));
        if ($cntJwt !== '' && str_starts_with($cntJwt, 'cnt-')) {
            // Direct Pay is configured but failed — do not also 404-spam the aggregator.
            return $direct;
        }

        // No cnt- JWT: last-resort try aggregator (historically 404 on /banking/disburse).
        return $this->postJson('/banking/disburse', $payload, 60, ['x-request-source: ' . $requestSource]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function disburseViaDirectPay(array $payload, string $requestSource): array
    {
        $cntJwt = trim((string) ($_ENV['CENTIIV_JWT'] ?? getenv('CENTIIV_JWT') ?: ''));
        if ($cntJwt === '' || !str_starts_with($cntJwt, 'cnt-')) {
            return [
                'success' => false,
                'http_code' => 0,
                'data' => [],
                'raw' => null,
                'error' => 'CENTIIV_JWT (cnt-) not configured for Direct Pay disburse',
            ];
        }

        $base = rtrim(trim((string) ($_ENV['CENTIIV_API_BASE'] ?? getenv('CENTIIV_API_BASE') ?: 'https://api.centiiv.com/api/v1')), '/');
        $url = $base . '/banking/disburse';
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $cntJwt,
            'x-request-source: ' . $requestSource,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);

            return [
                'success' => false,
                'http_code' => $code,
                'data' => [],
                'raw' => is_string($response) ? $response : null,
                'error' => 'Curl error: ' . $err,
            ];
        }
        curl_close($ch);

        return $this->decodeResponse('/banking/disburse', 'POST', $response, $code);
    }

    public static function intermediaryDisburseCacheDir(): string
    {
        return dirname(__DIR__, 2) . '/var/sendra_intermediary_disburse';
    }

    /**
     * Persist intermediary disburse context for receipt generation when disbursement webhook arrives.
     *
     * @param array<string, mixed> $ctx
     */
    public static function cacheIntermediaryDisburseContext(string $trackingId, array $ctx): void
    {
        $dir = self::intermediaryDisburseCacheDir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                error_log('Sendra intermediary disburse cache mkdir failed: ' . $dir);

                return;
            }
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $trackingId) ?? $trackingId;
        $path = $dir . '/' . $safe . '.json';
        $ctx['cached_at'] = time();
        $ok = @file_put_contents($path, json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($ok === false) {
            error_log('Sendra intermediary disburse cache write failed: ' . $path);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function loadIntermediaryDisburseContext(string $trackingId): ?array
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $trackingId) ?? $trackingId;
        $path = self::intermediaryDisburseCacheDir() . '/' . $safe . '.json';
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Idempotency lookup across webhook (order-*) and Daya/poller (intermediary:*) cache keys.
     *
     * @return array<string, mixed>|null
     */
    public static function loadIntermediaryDisburseContextForOrder(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }
        foreach (['order-' . $orderId, 'intermediary:' . $orderId] as $key) {
            $ctx = self::loadIntermediaryDisburseContext($key);
            if (is_array($ctx) && !empty($ctx['disburse_tracking_id'])) {
                return $ctx;
            }
        }

        return null;
    }

    /**
     * Write both cache key styles so webhook + poller share idempotency.
     *
     * @param array<string, mixed> $ctx
     */
    public static function cacheIntermediaryDisburseContextForOrder(string $orderId, string $trackingId, array $ctx): void
    {
        $orderId = trim($orderId);
        self::cacheIntermediaryDisburseContext($trackingId, $ctx);
        if ($orderId !== '') {
            self::cacheIntermediaryDisburseContext('order-' . $orderId, $ctx);
            self::cacheIntermediaryDisburseContext('intermediary:' . $orderId, $ctx);
        }
    }

    /**
     * Atomic claim so "Payout delayed" (pool shortfall) is WhatsApp'd at most once per order.
     * File left in place after first claim — webhook/poller retries must not re-spam the user.
     */
    public static function claimPoolShortfallUserNotify(string $orderId): bool
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return true;
        }
        $dir = self::intermediaryDisburseCacheDir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                error_log('Sendra pool shortfall notify mkdir failed: ' . $dir);

                return true;
            }
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $orderId) ?? $orderId;
        $path = $dir . '/shortfall_notified_' . $safe . '.json';
        $h = @fopen($path, 'xb');
        if ($h === false) {
            return false;
        }
        fwrite($h, (string) json_encode(['claimed_at' => gmdate('c'), 'order_id' => $orderId]));
        fclose($h);

        return true;
    }

    public static function poolShortfallUserNotifyClaimed(string $orderId): bool
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return false;
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $orderId) ?? $orderId;
        $path = self::intermediaryDisburseCacheDir() . '/shortfall_notified_' . $safe . '.json';

        return is_readable($path);
    }

    /**
     * Centiiv / NIBSS verify-account often returns the canonical settlement bank (nested `bank` or `bankAccount`).
     * Values here align with what {@see buildOfframpBeneficiaryWithVerify} merges into `bankAccount.bankCode`.
     *
     * @param array<string, mixed> $verifyEnvelope decoded verify JSON (may be wrapped in `data`)
     * @return array{code: string, name: ?string}|null null when verify did not supply a bank code
     */
    public static function resolvedBankFromVerifyEnvelope(array $verifyEnvelope): ?array
    {
        $root = $verifyEnvelope['data'] ?? $verifyEnvelope;
        if (!is_array($root)) {
            return null;
        }

        $code = '';
        $name = null;

        if (isset($root['bank']) && is_array($root['bank'])) {
            $bk = $root['bank'];
            $code = (string) ($bk['bankCode'] ?? $bk['code'] ?? $bk['bank_code'] ?? '');
            $nameRaw = $bk['bankName'] ?? $bk['name'] ?? $bk['longName'] ?? null;
            if (is_string($nameRaw) && $nameRaw !== '') {
                $name = $nameRaw;
            }
        }

        $nestedBa = $root['bankAccount'] ?? null;
        if (is_array($nestedBa)) {
            $c2 = (string) ($nestedBa['bankCode'] ?? $nestedBa['bank_code'] ?? '');
            if ($c2 !== '') {
                $code = $code !== '' ? $code : $c2;
            }
            $n2 = $nestedBa['bankName'] ?? $nestedBa['bank_name'] ?? null;
            if (is_string($n2) && $n2 !== '' && ($name === null || $name === '')) {
                $name = $n2;
            }
        }

        $flat = (string) ($root['bankCode'] ?? $root['bank_code'] ?? '');
        if ($flat !== '' && $code === '') {
            $code = $flat;
        }

        if ($code === '') {
            return null;
        }

        return ['code' => self::normalizeCentiivBankCode($code), 'name' => $name];
    }

    /**
     * @param array<string, mixed> $data
     * @param float|null $fromAssetAmountForTotalEstimate when API returns rate but not total NGN, multiply rate × this (e.g. quoted USDC amount)
     * @return array{receive_ngn: ?float, receive_ngn_display: string, rate_hint: ?string, fee_hint: ?string}
     */
    public static function currencySymbol(string $fiat): string
    {
        $fiat = strtoupper(trim($fiat));
        return match ($fiat) {
            'KES' => 'KSh ',
            'GHS' => 'GH₵',
            'XOF', 'XAF' => 'CFA ',
            default => '₦',
        };
    }

    public static function summarizeQuoteFiat(array $data, string $fiat = 'NGN', ?float $fromAssetAmountForTotalEstimate = null): array
    {
        $nested = $data['data'] ?? $data['quote'] ?? $data;
        if (!is_array($nested)) {
            $nested = $data;
        }

        $toBlock = isset($nested['to']) && is_array($nested['to']) ? $nested['to'] : [];

        $receive = self::firstNumeric([
            $nested['estimatedReceivableAmount'] ?? null,
            $nested['estimated_receivable_amount'] ?? null,
            $nested['receiveAmount'] ?? null,
            $nested['toAmount'] ?? null,
            $nested['outputAmount'] ?? null,
            $nested['amountTo'] ?? null,
            $nested['ngnAmount'] ?? null,
            $nested['fiatAmount'] ?? null,
            $nested['youReceive'] ?? null,
            $nested['you_receive'] ?? null,
            $nested['estimatedReceive'] ?? null,
            $nested['estimated_receive'] ?? null,
            $nested['payoutAmount'] ?? null,
            $nested['payout_amount'] ?? null,
            $nested['totalPayout'] ?? null,
            $nested['total_payout'] ?? null,
            $nested['amountNgn'] ?? null,
            $nested['amount_ngn'] ?? null,
            $nested['toNgn'] ?? null,
            $nested['to_ngn'] ?? null,
            $nested['ngn'] ?? null,
            $toBlock['amount'] ?? null,
            $toBlock['value'] ?? null,
            $data['receiveAmount'] ?? null,
        ]);

        $fee = self::firstNumeric([
            $nested['fee'] ?? null,
            $nested['feeAmount'] ?? null,
            $nested['aggregatorFee'] ?? null,
            $data['fee'] ?? null,
        ]);

        $rate = self::firstNumeric([
            $nested['rate'] ?? null,
            $nested['effectiveRate'] ?? null,
            $nested['finalRate'] ?? null,
            $nested['exchangeRate'] ?? null,
            $nested['fxRate'] ?? null,
            $data['rate'] ?? null,
        ]);

        if ($receive === null && $rate !== null && $fromAssetAmountForTotalEstimate !== null && $fromAssetAmountForTotalEstimate > 0) {
            $receive = round($rate * $fromAssetAmountForTotalEstimate, 2);
        }

        $receiveDisplay = $receive !== null
            ? number_format($receive, 2, '.', ',')
            : '—';

        $sym = self::currencySymbol($fiat);
        $rateHint = $rate !== null ? ('≈ ' . $sym . number_format($rate, 2, '.', ',') . ' / unit') : null;
        $feeHint = $fee !== null ? ('Fee (est.): ' . $sym . number_format($fee, 2, '.', ',')) : null;

        return [
            'receive_fiat' => $receive,
            'receive_ngn' => $receive,
            'receive_fiat_display' => $receiveDisplay,
            'receive_ngn_display' => $receiveDisplay,
            'rate_hint' => $rateHint,
            'fee_hint' => $feeHint,
        ];
    }

    public static function summarizeQuoteNgn(array $data, ?float $fromAssetAmountForTotalEstimate = null): array
    {
        return self::summarizeQuoteFiat($data, 'NGN', $fromAssetAmountForTotalEstimate);
    }

    /**
     * Official NGN-per-crypto rate from a Centiiv order or quote payload (metadata.rate, quote.rate, etc.).
     *
     * @param array<string, mixed> $data
     */
    public static function extractOfficialNgnRateFromOrder(array $data): ?float
    {
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $innerMeta = is_array($meta['metadata'] ?? null) ? $meta['metadata'] : [];
        $quote = is_array($meta['quote'] ?? null) ? $meta['quote'] : (
            is_array($innerMeta['quote'] ?? null) ? $innerMeta['quote'] : null
        );

        $candidates = [
            $meta['rate'] ?? null,
            $innerMeta['rate'] ?? null,
            $data['rate'] ?? null,
            $data['effectiveRate'] ?? null,
            $data['finalRate'] ?? null,
            $data['exchangeRate'] ?? null,
            $data['fxRate'] ?? null,
        ];
        if (is_array($quote)) {
            foreach (['rate', 'effectiveRate', 'finalRate', 'exchangeRate', 'fxRate'] as $k) {
                $candidates[] = $quote[$k] ?? null;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_numeric($candidate) && (float) $candidate > 0) {
                return (float) $candidate;
            }
        }

        return null;
    }

    /**
     * NGN per 1 unit of crypto from a Centiiv quote/order payload.
     * On-ramp (NGN→USDC) quotes return crypto-per-NGN (e.g. 0.00071); off-ramp returns NGN-per-crypto.
     *
     * @param array<string, mixed> $data
     */
    public static function ngnPerCryptoFromCentiivQuoteData(
        array $data,
        ?float $fromAmount = null,
        ?float $receiveCrypto = null,
    ): ?float {
        $nested = $data['data'] ?? $data['quote'] ?? $data;
        if (!is_array($nested)) {
            $nested = $data;
        }

        $fromAsset = strtoupper(trim((string) ($nested['fromAsset'] ?? $data['fromAsset'] ?? '')));
        $rate = self::extractOfficialNgnRateFromOrder($data);
        if ($rate === null || $rate <= 0) {
            $rate = self::firstNumeric([
                $nested['rate'] ?? null,
                $nested['effectiveRate'] ?? null,
                $nested['finalRate'] ?? null,
                $nested['exchangeRate'] ?? null,
                $nested['fxRate'] ?? null,
            ]);
        }

        if ($rate !== null && $rate > 0) {
            if ($fromAsset === 'NGN' || ($fromAsset === '' && $rate < 1)) {
                return 1 / $rate;
            }

            return $rate;
        }

        if ($fromAmount !== null && $receiveCrypto !== null && $fromAmount > 0 && $receiveCrypto > 0
            && ($fromAsset === 'NGN' || $fromAsset === '')) {
            return $fromAmount / $receiveCrypto;
        }

        return null;
    }

    public static function formatNgnPerCryptoRateHint(?float $ngnPerCrypto): ?string
    {
        if ($ngnPerCrypto === null || $ngnPerCrypto <= 0) {
            return null;
        }

        return '≈ ₦' . number_format($ngnPerCrypto, 2, '.', ',') . ' / unit';
    }

    /**
     * Implied NGN per 1 unit of crypto (fallback when Centiiv rate field is missing).
     */
    public static function ngnPerCryptoRateHint(?float $payNgn, ?float $receiveCrypto): ?string
    {
        return self::formatNgnPerCryptoRateHint(
            ($payNgn !== null && $receiveCrypto !== null && $payNgn > 0 && $receiveCrypto > 0)
                ? ($payNgn / $receiveCrypto)
                : null
        );
    }

    /**
     * NGN per 1 USDC from a forward USDC→NGN quote payload (rate field or receive ÷ probe amount).
     */
    public static function ngnPerUsdcFromQuoteData(array $data, float $probeUsdcAmount = 1.0): ?float
    {
        $nested = $data['data'] ?? $data['quote'] ?? $data;
        if (!is_array($nested)) {
            $nested = $data;
        }
        $rate = self::firstNumeric([
            $nested['rate'] ?? null,
            $nested['effectiveRate'] ?? null,
            $nested['finalRate'] ?? null,
            $nested['exchangeRate'] ?? null,
            $data['rate'] ?? null,
        ]);
        if ($rate !== null && $rate > 0) {
            return $rate;
        }
        if ($probeUsdcAmount <= 0) {
            return null;
        }
        $sum = self::summarizeQuoteNgn($data, $probeUsdcAmount);
        $recv = $sum['receive_ngn'] ?? null;
        if ($recv !== null && $recv > 0) {
            return $recv / $probeUsdcAmount;
        }

        return null;
    }

    /**
     * Size USDC off-ramp for a target NGN payout without binary search.
     * Centiiv /public/quote only supports exact-in (USDC amount); off-ramp USDC→NGN is linear at ~flat rate.
     *
     * @return array{
     *   usdc_amount: float,
     *   usdc_amount_str: string,
     *   sum: array{receive_ngn: ?float, receive_ngn_display: string, rate_hint: ?string, fee_hint: ?string},
     *   centiiv_min_usdc?: float
     * }|null null = quote failure
     */
    public function solveUsdcForTargetNgn(float $targetNgn, float $toleranceNgn = 1.0): ?array
    {
        return $this->solveUsdcForTargetFiat($targetNgn, 'NGN', $toleranceNgn);
    }

    /**
     * @param array{fromAsset?: string, network?: string} $quoteExtra
     */
    public function solveUsdcForTargetFiat(float $targetFiat, string $fiat = 'NGN', float $toleranceFiat = 1.0, array $quoteExtra = []): ?array
    {
        $fiat = strtoupper(trim($fiat));
        if ($targetFiat <= 0) {
            return null;
        }
        $fromAsset = strtoupper((string) ($quoteExtra['fromAsset'] ?? 'USDC'));
        $network = strtoupper((string) ($quoteExtra['network'] ?? ''));
        $quoteBody = static function (float $amount) use ($fromAsset, $fiat, $network): array {
            $body = [
                'fromAsset' => $fromAsset,
                'toAsset' => $fiat,
                'amount' => $amount,
            ];
            if ($network !== '') {
                $body['network'] = $network;
            }

            return $body;
        };
        $probeUsdc = 1.0;
        $probe = $this->getQuote($quoteBody($probeUsdc));
        if (!$probe['success']) {
            $minUsdc = self::parseMinUsdcFromQuoteError(is_string($probe['error'] ?? null) ? $probe['error'] : null);

            return $minUsdc !== null ? ['centiiv_min_usdc' => $minUsdc] : null;
        }
        $sumProbe = self::summarizeQuoteFiat($probe['data'], $fiat, $probeUsdc);
        $fiatPerUsdc = (float) ($sumProbe['receive_fiat'] ?? 0);
        if ($fiatPerUsdc <= 0) {
            return null;
        }

        $usdc = $targetFiat / $fiatPerUsdc;
        $maxAdjust = 2;
        for ($adj = 0; $adj <= $maxAdjust; $adj++) {
            if ($usdc < 0.5) {
                $minQ = $this->getQuote($quoteBody(0.5));
                if (!$minQ['success']) {
                    $minUsdc = self::parseMinUsdcFromQuoteError($minQ['error'] ?? null);

                    return $minUsdc !== null ? ['centiiv_min_usdc' => $minUsdc] : null;
                }
                $minSum = self::summarizeQuoteFiat($minQ['data'], $fiat, 0.5);
                $minRecv = (float) ($minSum['receive_fiat'] ?? 0);
                if ($minRecv + 1e-6 < $targetFiat) {
                    return ['centiiv_min_usdc' => 0.5];
                }
                $usdc = 0.5;
            }

            $usdcStr = self::formatUsdcAmount($usdc);
            $confirm = $this->getQuote($quoteBody((float) $usdcStr));
            if (!$confirm['success']) {
                $minUsdc = self::parseMinUsdcFromQuoteError($confirm['error'] ?? null);
                if ($minUsdc !== null) {
                    $usdc = max($usdc, $minUsdc);

                    continue;
                }

                return null;
            }
            $sum = self::summarizeQuoteFiat($confirm['data'], $fiat, (float) $usdcStr);
            $recv = (float) ($sum['receive_fiat'] ?? 0);
            if ($recv + 1e-9 >= $targetFiat - $toleranceFiat) {
                return [
                    'usdc_amount' => (float) $usdcStr,
                    'usdc_amount_str' => $usdcStr,
                    'sum' => $sum,
                ];
            }
            $shortfall = $targetFiat - $recv;
            if ($shortfall > 0 && $fiatPerUsdc > 0) {
                $usdc += ($shortfall / $fiatPerUsdc) * 1.002;
            } else {
                $usdc *= 1.005;
            }
        }

        return null;
    }

    /**
     * Centiiv quote/order errors use either wording:
     * - "minimum transaction amount of 0.5"
     * - "Amount is below the minimum of 0.5 for this rail"
     */
    public static function parseMinUsdcFromQuoteError(?string $error): ?float
    {
        if ($error === null || $error === '') {
            return null;
        }
        if (preg_match('/minimum(?:\s+transaction\s+amount)?\s+of\s*([0-9]+(?:\.[0-9]+)?)/i', $error, $m) !== 1) {
            return null;
        }
        $v = (float) $m[1];

        return $v > 0 ? $v : null;
    }

    public static function bankNameUnresolvedUserMessage(string $bankNameGuess, string $fiat = 'NGN'): string
    {
        if (strtoupper($fiat) === 'KES' || str_contains(strtolower($bankNameGuess), 'mpesa') || str_contains(strtolower($bankNameGuess), 'm-pesa')) {
            return "❌ Could not resolve *{$bankNameGuess}* as a bank.\n\n"
                . 'Check the *bank name* and *account/phone number*, then try again '
                . '(e.g. `0758901726 Mpesa` or `0758901726 KCB`).';
        }

        return "❌ Could not resolve *{$bankNameGuess}* as a bank.\n\n"
            . 'Check the *bank name* and *10-digit account number*, then try again '
            . '(e.g. `0123456789 GTBank` or `0123456789 Union Bank`).';
    }

    public static function bankVerifyFailureUserMessage(
        string $error,
        string $bankNameGuess = '',
        string $accountNumber = '',
        string $fiat = 'NGN'
    ): string {
        if (preg_match('/account.*not found|not found on this bank|invalid account/i', $error)) {
            $bankPart = $bankNameGuess !== '' ? " for *{$bankNameGuess}*" : '';
            $example = $accountNumber !== '' && $bankNameGuess !== ''
                ? "\n\nExample: `{$accountNumber} {$bankNameGuess}`"
                : (strtoupper($fiat) === 'KES' ? "\n\nExample: `0758901726 Mpesa`" : "\n\nExample: `0123456789 Union Bank`");

            return "❌ Could not resolve that account number{$bankPart}.\n\n"
                . 'Double-check the *account/phone number* and *bank name*, then try again.'
                . $example;
        }
        if (preg_match('/invalid bank|bank.*not found|unknown bank|invalid bank code/i', $error)) {
            return self::bankNameUnresolvedUserMessage($bankNameGuess !== '' ? $bankNameGuess : 'that bank', $fiat);
        }
        if (stripos($error, 'timed out') !== false || stripos($error, 'Invalid or empty JSON') !== false) {
            return "❌ Bank verification is temporarily unavailable.\n\nTry again in a minute.";
        }

        $bankPart = $bankNameGuess !== '' ? " with *{$bankNameGuess}*" : '';

        return "❌ Could not verify that bank account{$bankPart}.\n\n"
            . 'Check the *account number* and *bank name*, then try again.';
    }

    public function buildMinPayoutUserMessage(float $minUsdc, string $fiat = 'NGN'): string
    {
        $fiat = strtoupper(trim($fiat));
        if ($fiat === '') {
            $fiat = 'NGN';
        }
        $q = $this->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => $fiat,
            'amount' => $minUsdc,
        ]);
        if ($q['success']) {
            $s = self::summarizeQuoteFiat($q['data'], $fiat, $minUsdc);
            $minFiat = (float) ($s['receive_fiat'] ?? 0);
            if ($minFiat > 0) {
                $sym = self::currencySymbol($fiat);
                $minFiatFormatted = number_format($minFiat, 2, '.', ',');
                return
                    "❌ Amount too small for bank payout right now.\n" .
                    "Minimum is *≈ {$sym}{$minFiatFormatted}*.\n" .
                    'Try a higher amount (e.g. *' . rtrim(rtrim(number_format($minUsdc, 6, '.', ''), '0'), '.') . ' USDC* or more).';
            }
        }

        return
            "❌ Amount too small for bank payout right now.\n" .
            'Minimum is *' . rtrim(rtrim(number_format($minUsdc, 6, '.', ''), '0'), '.') . "* USDC on this rail.\n" .
            'Try a higher amount.';
    }

    public function quoteFailureUserMessage(string $error, string $fiat = 'NGN'): string
    {
        if (preg_match('/maximum transaction amount of 0/i', $error)) {
            $label = strtoupper(trim($fiat)) !== '' ? strtoupper(trim($fiat)) : 'Cash';
            return "❌ {$label} payout is temporarily unavailable right now.\n\nPlease try again later.";
        }

        $minUsdc = self::parseMinUsdcFromQuoteError($error);
        if ($minUsdc !== null) {
            return $this->buildMinPayoutUserMessage($minUsdc, $fiat);
        }

        if (preg_match('/outside the allowed transaction limits|transaction limit/i', $error)) {
            $fiatLabel = strtoupper(trim($fiat)) === 'XOF' ? 'XOF (CFA)' : (strtoupper(trim($fiat)) !== '' ? strtoupper(trim($fiat)) : 'cash');
            return "❌ Amount is outside the allowed limits for {$fiatLabel} payout.\n\nMinimum limit is *1 USDC* (~560 CFA). Try an amount of *1 USDC* or higher.";
        }

        $body = '❌ No rate available right now.';
        if (preg_match('/no rail|no route|no liquidity|no corridor|liquidity provider/i', $error)) {
            $body .= "\n\nTry *USDC*, another amount, or again later.";
        } else {
            $body .= "\n\nTry again shortly.";
        }

        return $body;
    }

    public static function formatUsdcAmountForApi(float $usdc): string
    {
        return self::formatUsdcAmount($usdc);
    }

    /**
     * USDC amount to pass to Centiiv quote APIs when sizing intermediary pool payout.
     * Quotes must match {@see centiivOrderUsdcFromWalletUsdc()} (rail deposit), not raw wallet balance.
     */
    public static function centiivQuoteUsdcForIntermediaryPool(float $walletUsdcOut): float
    {
        if ($walletUsdcOut <= 0) {
            return 0.0;
        }
        if (!self::isIntermediaryNgnPayoutEnabled()) {
            return $walletUsdcOut;
        }

        return self::centiivOrderUsdcFromWalletUsdc($walletUsdcOut);
    }

    public static function intermediaryPoolShortfallToleranceNgn(): float
    {
        $raw = trim((string) ($_ENV['SENDRA_INTERMEDIARY_POOL_SHORTFALL_TOLERANCE_NGN'] ?? getenv('SENDRA_INTERMEDIARY_POOL_SHORTFALL_TOLERANCE_NGN') ?: '0'));
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }

        return max(0.0, min(5.0, (float) $raw));
    }

    /**
     * Centiiv bank disburse fee (deducted from pool when paying the user's bank).
     * ≤ threshold: low fee; above threshold: high fee.
     */
    public static function intermediaryBankDisburseFeeNgn(float $requestedNgn): float
    {
        if ($requestedNgn <= 0) {
            return 0.0;
        }

        $thresholdRaw = trim((string) ($_ENV['SENDRA_CENTIIV_DISBURSE_FEE_THRESHOLD_NGN'] ?? getenv('SENDRA_CENTIIV_DISBURSE_FEE_THRESHOLD_NGN') ?: '10000'));
        $threshold = is_numeric($thresholdRaw) ? (float) $thresholdRaw : 10000.0;
        $threshold = max(100.0, min(1000000.0, $threshold));

        $lowRaw = trim((string) ($_ENV['SENDRA_CENTIIV_DISBURSE_FEE_NGN_LOW'] ?? getenv('SENDRA_CENTIIV_DISBURSE_FEE_NGN_LOW') ?: '50'));
        $highRaw = trim((string) ($_ENV['SENDRA_CENTIIV_DISBURSE_FEE_NGN_HIGH'] ?? getenv('SENDRA_CENTIIV_DISBURSE_FEE_NGN_HIGH') ?: '100'));
        $low = is_numeric($lowRaw) ? (float) $lowRaw : 50.0;
        $high = is_numeric($highRaw) ? (float) $highRaw : 100.0;
        $low = max(0.0, min(500.0, $low));
        $high = max($low, min(500.0, $high));

        return $requestedNgn > $threshold ? $high : $low;
    }

    /** Minimum pool credit required before bank disburse (user payout + Centiiv transfer fee). */
    public static function intermediaryPoolMinimumReceivedNgn(float $requestedNgn): float
    {
        if ($requestedNgn <= 0) {
            return 0.0;
        }

        return $requestedNgn + self::intermediaryBankDisburseFeeNgn($requestedNgn);
    }

    /**
     * Pool NGN sizing floor: user request + Centiiv disburse fee + slippage buffer.
     */
    public static function intermediaryPoolFloorNgn(float $requestedNgn): float
    {
        if ($requestedNgn <= 0) {
            return 0.0;
        }
        if (!self::isIntermediaryNgnPayoutEnabled()) {
            return $requestedNgn;
        }

        $extra = SendraOfframpSlippageService::extraNgnBuffer();
        if ($extra <= 0) {
            $raw = trim((string) ($_ENV['SENDRA_INTERMEDIARY_POOL_NGN_BUFFER'] ?? getenv('SENDRA_INTERMEDIARY_POOL_NGN_BUFFER') ?: '35'));
            $extra = is_numeric($raw) ? max(0.0, (float) $raw) : 35.0;
        }

        return self::intermediaryPoolMinimumReceivedNgn($requestedNgn) + $extra;
    }

    /** True when pool fulfillment covers the user's bank payout (no partial disburse). */
    public static function intermediaryPoolCoversUserPayout(float $requestedNgn, float $receivedNgn): bool
    {
        if ($requestedNgn < 100 || $receivedNgn <= 0) {
            return false;
        }

        return $receivedNgn + self::intermediaryPoolShortfallToleranceNgn() + 1e-9
            >= self::intermediaryPoolMinimumReceivedNgn($requestedNgn);
    }

    /**
     * Pick Centiiv order USDC so quoted pool NGN meets the intermediary floor (requested + buffer).
     * Prefers haircut-safe amount; bumps to full wallet USDC when haircut would underpay.
     *
     * @return array{order_usdc: float, quoted_ngn: ?float}
     */
    public static function resolveCentiivOrderUsdcForPoolFloor(
        float $walletUsdcOut,
        float $poolFloorNgn,
        self $agg
    ): array {
        $haircut = self::centiivOrderUsdcFromWalletUsdc($walletUsdcOut);
        $candidates = [$haircut];
        if ($walletUsdcOut > $haircut + 1e-9) {
            $candidates[] = $walletUsdcOut;
        }

        $bestUsdc = $haircut;
        $bestRecv = null;
        foreach ($candidates as $usdc) {
            if ($usdc <= 0) {
                continue;
            }
            $q = $agg->getQuote([
                'fromAsset' => 'USDC',
                'toAsset' => 'NGN',
                'amount' => $usdc,
            ]);
            if (!$q['success']) {
                continue;
            }
            $sum = self::summarizeQuoteNgn($q['data'], $usdc);
            $recv = isset($sum['receive_ngn']) ? (float) $sum['receive_ngn'] : null;
            if ($recv !== null && $recv + 1e-9 >= $poolFloorNgn) {
                return ['order_usdc' => $usdc, 'quoted_ngn' => $recv];
            }
            if ($recv !== null && ($bestRecv === null || $recv > $bestRecv)) {
                $bestRecv = $recv;
                $bestUsdc = $usdc;
            }
        }

        return ['order_usdc' => $bestUsdc, 'quoted_ngn' => $bestRecv];
    }

    /**
     * Expected USDC credited on Centiiv Stellar after Aptos→Stellar bridge (slightly less than wallet send).
     * Used for Centiiv order amount only — not for WhatsApp PIN “≈ ₦X” (that uses full wallet USDC quote).
     */
    public static function expectedStellarDepositUsdc(float $walletUsdcOut): float
    {
        if ($walletUsdcOut <= 0) {
            return 0.0;
        }
        $bps = SendraOfframpSlippageService::effectiveHaircutBps();

        return $walletUsdcOut * (10000 - $bps) / 10000;
    }

    /**
     * Centiiv /public/order USDC amount from post-swap wallet balance.
     * Applies bridge haircut when safely above rail min; otherwise orders full wallet (all bridged).
     */
    public static function centiivOrderUsdcFromWalletUsdc(float $walletUsdcOut, float $minRailUsdc = 0.5): float
    {
        if ($walletUsdcOut <= 0) {
            return 0.0;
        }
        if ($walletUsdcOut + 1e-9 < $minRailUsdc) {
            return self::expectedStellarDepositUsdc($walletUsdcOut);
        }
        $haircut = self::expectedStellarDepositUsdc($walletUsdcOut);
        if ($haircut + 1e-9 >= $minRailUsdc) {
            return $haircut;
        }

        return $walletUsdcOut;
    }

    public static function walletUsdcMeetsCentiivRailMin(float $walletUsdcOut, float $minRailUsdc = 0.5): bool
    {
        return $walletUsdcOut + 1e-9 >= $minRailUsdc;
    }

    private static function formatUsdcAmount(float $usdc): string
    {
        $s = rtrim(rtrim(number_format($usdc, 6, '.', ''), '0'), '.');

        return $s !== '' ? $s : '0.01';
    }

    /**
     * Summarize crypto receive for on-ramp quotes (e.g. NGN → USDC).
     *
     * @param array<string, mixed> $data
     * @param float|null $fromNgnAmountForTotalEstimate when API returns rate but not total crypto, multiply rate × this
     * @return array{receive_crypto: ?float, receive_crypto_display: string, rate_hint: ?string, fee_hint: ?string}
     */
    public static function summarizeQuoteCrypto(array $data, ?float $fromNgnAmountForTotalEstimate = null): array
    {
        $nested = $data['data'] ?? $data['quote'] ?? $data;
        if (!is_array($nested)) {
            $nested = $data;
        }

        $toBlock = isset($nested['to']) && is_array($nested['to']) ? $nested['to'] : [];

        $receive = self::firstNumeric([
            $nested['receiveAmount'] ?? null,
            $nested['toAmount'] ?? null,
            $nested['outputAmount'] ?? null,
            $nested['amountTo'] ?? null,
            $nested['cryptoAmount'] ?? null,
            $nested['usdcAmount'] ?? null,
            $nested['usdtAmount'] ?? null,
            $nested['youReceive'] ?? null,
            $nested['estimatedReceive'] ?? null,
            $nested['estimatedReceivableAmount'] ?? null,
            $nested['amountOut'] ?? null,
            $toBlock['amount'] ?? null,
            $toBlock['value'] ?? null,
            $data['receiveAmount'] ?? null,
            $data['estimatedReceivableAmount'] ?? null,
        ]);

        $fee = self::firstNumeric([
            $nested['fee'] ?? null,
            $nested['feeAmount'] ?? null,
            $data['fee'] ?? null,
        ]);

        $rate = self::firstNumeric([
            $nested['rate'] ?? null,
            $nested['effectiveRate'] ?? null,
            $nested['exchangeRate'] ?? null,
            $data['rate'] ?? null,
        ]);

        $metaBlock = isset($nested['metadata']) && is_array($nested['metadata']) ? $nested['metadata'] : [];
        if ($rate === null) {
            $rate = self::firstNumeric([$metaBlock['rate'] ?? null]);
        }

        if ($receive === null && $rate !== null && $rate > 0
            && $fromNgnAmountForTotalEstimate !== null && $fromNgnAmountForTotalEstimate > 0) {
            $fromAsset = strtoupper(trim((string) ($nested['fromAsset'] ?? $data['fromAsset'] ?? '')));
            if ($fromAsset === 'NGN') {
                $receive = round($fromNgnAmountForTotalEstimate * $rate, 6);
            } else {
                $receive = round($fromNgnAmountForTotalEstimate / $rate, 6);
            }
        }

        if ($receive === null) {
            $onrampCrypto = self::extractOnrampCryptoAtCentiiv($nested);
            if ($onrampCrypto !== null) {
                $receive = $onrampCrypto['amount'];
            }
        }

        $receiveDisplay = $receive !== null
            ? rtrim(rtrim(number_format($receive, 6, '.', ''), '0'), '.')
            : '—';

        $ngnPerCrypto = self::ngnPerCryptoFromCentiivQuoteData($data, $fromNgnAmountForTotalEstimate, $receive);
        $rateHint = self::formatNgnPerCryptoRateHint($ngnPerCrypto)
            ?? self::ngnPerCryptoRateHint($fromNgnAmountForTotalEstimate, $receive);
        $feeHint = $fee !== null ? ('Fee (est.): ₦' . number_format($fee, 2, '.', ',')) : null;

        return [
            'receive_crypto' => $receive,
            'receive_crypto_display' => $receiveDisplay,
            'rate_hint' => $rateHint,
            'fee_hint' => $feeHint,
        ];
    }

    /**
     * Crypto Centiiv processed on fulfilled on-ramp orders (NGN → USDC/USDT).
     *
     * @param array<string, mixed> $data get-order / webhook JSON
     * @return array{amount: float, asset: string}|null
     */
    public static function extractOnrampCryptoAtCentiiv(array $data): ?array
    {
        $nested = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        if (!is_array($nested)) {
            return null;
        }

        $fromAsset = strtoupper(trim((string) ($nested['fromAsset'] ?? '')));
        $toAsset = strtoupper(trim((string) ($nested['toAsset'] ?? '')));
        $type = strtoupper(trim((string) ($nested['type'] ?? '')));
        $isOnramp = $type === 'ONRAMP'
            || ($fromAsset === 'NGN' && $toAsset !== '' && $toAsset !== 'NGN');
        if (!$isOnramp) {
            return null;
        }

        $asset = $toAsset !== '' ? $toAsset : 'USDC';

        if (isset($nested['fulfillments'][0]) && is_array($nested['fulfillments'][0])) {
            $f0 = $nested['fulfillments'][0];
            $fulAmt = self::firstNumeric([
                $f0['amount'] ?? null,
                $f0['cryptoAmount'] ?? null,
                $f0['crypto_amount'] ?? null,
            ]);
            if ($fulAmt !== null && $fulAmt > 0) {
                return ['amount' => $fulAmt, 'asset' => $asset];
            }
        }

        $metaBlock = isset($nested['metadata']) && is_array($nested['metadata']) ? $nested['metadata'] : [];
        $rate = self::firstNumeric([
            $metaBlock['rate'] ?? null,
            $nested['rate'] ?? null,
        ]);
        $ngnAmt = self::firstNumeric([$nested['amount'] ?? null]);
        if ($rate !== null && $rate > 0 && $ngnAmt !== null && $ngnAmt > 0) {
            return ['amount' => round($ngnAmt * $rate, 6), 'asset' => $asset];
        }

        return null;
    }

    /**
     * Virtual NGN bank account for on-ramp (user pays fiat to fund the order).
     *
     * @param array<string, mixed> $data create-order or get-order JSON
     * @return array{account_number: string, account_name: string, bank_name: string, bank_code: ?string}|null
     */
    public static function extractVirtualBankAccount(array $data): ?array
    {
        $preferredKeys = [
            'virtualAccount', 'virtual_account', 'fundingAccount', 'funding_account',
            'payInAccount', 'pay_in_account', 'bankAccount', 'bank_account',
            'temporaryWallet', 'temporary_wallet',
        ];

        foreach ($preferredKeys as $k) {
            if (empty($data[$k]) || !is_array($data[$k])) {
                continue;
            }
            $parsed = self::parseBankAccountBlock($data[$k]);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $beneficiary = $data['beneficiary'] ?? null;
        if (is_array($beneficiary)) {
            $details = $beneficiary['details'] ?? $beneficiary['bankAccount'] ?? $beneficiary;
            if (is_array($details)) {
                $parsed = self::parseBankAccountBlock($details);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        foreach ($data as $v) {
            if (is_array($v)) {
                $f = self::extractVirtualBankAccount($v);
                if ($f !== null) {
                    return $f;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     * @return array{account_number: string, account_name: string, bank_name: string, bank_code: ?string}|null
     */
    private static function parseBankAccountBlock(array $block): ?array
    {
        $network = strtoupper((string) ($block['network'] ?? $block['chain'] ?? ''));
        if ($network !== '' && in_array($network, ['STELLAR', 'APTOS', 'APT', 'ETH', 'SOL', 'SOLANA'], true)) {
            return null;
        }

        $acct = (string) ($block['accountNumber'] ?? $block['account_number'] ?? $block['publicAddress'] ?? $block['address'] ?? '');
        if ($acct === '' || self::looksLikeStellarAccountId($acct) || str_starts_with($acct, '0x')) {
            return null;
        }

        $name = (string) ($block['accountName'] ?? $block['account_name'] ?? $block['name'] ?? '');
        $bank = (string) ($block['bankName'] ?? $block['bank_name'] ?? $block['bank'] ?? '');
        $code = isset($block['bankCode']) ? (string) $block['bankCode'] : (isset($block['bank_code']) ? (string) $block['bank_code'] : null);

        if ($name === '' && $bank === '') {
            return null;
        }

        return [
            'account_number' => $acct,
            'account_name' => $name !== '' ? $name : 'Sendra / Centiiv',
            'bank_name' => self::displayVirtualBankName($bank !== '' ? $bank : 'Virtual account', $code),
            'bank_code' => $code,
        ];
    }

    /**
     * User-facing bank label for Centiiv virtual accounts (API may lag provider rebrand).
     */
    public static function displayVirtualBankName(string $bankName, ?string $bankCode = null): string
    {
        $trimmed = trim($bankName);
        if ($trimmed === '') {
            return 'Virtual account';
        }

        $key = strtolower(preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed);
        if (isset(self::CENTIIV_VA_BANK_DISPLAY_OVERRIDES[$key])) {
            return self::CENTIIV_VA_BANK_DISPLAY_OVERRIDES[$key];
        }

        foreach (self::CENTIIV_VA_BANK_DISPLAY_OVERRIDES as $needle => $label) {
            if (str_contains($key, $needle)) {
                return $label;
            }
        }

        // Amucha MFB virtual accounts (Centiiv bankCode 090645) → Nombank rebrand
        if ($bankCode === '090645' || str_contains($key, 'amucha')) {
            return 'Nombank Microfinance Bank';
        }

        return $trimmed;
    }

    /**
     * Outbound crypto tx from Centiiv order metadata (Stellar USDC sent to 1Click).
     *
     * @param array<string, mixed> $data
     * @return array{tx_hash: ?string, sender: ?string}|null
     */
    public static function extractOutboundCryptoTx(array $data): ?array
    {
        $meta = $data['metadata'] ?? [];
        if (is_object($meta)) {
            $meta = (array) $meta;
        }
        if (!is_array($meta)) {
            $meta = [];
        }
        $inner = $meta['metadata'] ?? null;
        if (is_array($inner)) {
            $meta = array_merge($meta, $inner);
        }

        $hash = $meta['depositTxHash'] ?? $meta['deposit_tx_hash'] ?? $meta['payoutTxHash'] ?? null;
        $sender = $meta['depositSenderAddress'] ?? $meta['deposit_sender_address'] ?? null;

        if (!is_string($hash) || $hash === '') {
            $fulfillments = $data['fulfillments'] ?? null;
            if (is_array($fulfillments)) {
                foreach ($fulfillments as $fulfillment) {
                    if (!is_array($fulfillment)) {
                        continue;
                    }
                    $hash = $fulfillment['disbursalTxHash'] ?? $fulfillment['disbursal_tx_hash'] ?? null;
                    if (is_string($hash) && $hash !== '') {
                        $sender = $fulfillment['walletAddress'] ?? $fulfillment['wallet_address'] ?? $sender;

                        break;
                    }
                }
            }
        }

        if (!is_string($hash) || $hash === '') {
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $nested = self::extractOutboundCryptoTx($v);
                    if ($nested !== null && !empty($nested['tx_hash'])) {
                        return $nested;
                    }
                }
            }

            return null;
        }

        return [
            'tx_hash' => $hash,
            'sender' => is_string($sender) && $sender !== '' ? $sender : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function extractAptosDepositAddress(array $data): ?string
    {
        $preferredKeys = [
            'depositAddress', 'deposit_address', 'fundingAddress', 'funding_address',
            'payInAddress', 'pay_in_address', 'temporaryWallet', 'temporary_wallet',
            'walletAddress', 'wallet_address', 'cryptoDepositAddress',
        ];

        return self::findAddressWithKeys($data, $preferredKeys);
    }

    /**
     * Stellar public account ID (Centiiv per-order USDC pay-in on Stellar).
     */
    public static function looksLikeStellarAccountId(string $s): bool
    {
        $s = trim($s);

        return $s !== '' && (bool) preg_match('/^G[A-Z2-7]{55}$/', $s);
    }

    public static function looksLikeSolanaAddress(string $s): bool
    {
        $s = self::normalizeSolanaAddressInput($s);
        if ($s === '' || self::looksLikeStellarAccountId($s) || str_starts_with(strtolower($s), '0x')) {
            return false;
        }
        $len = strlen($s);
        if ($len < 32 || $len > 44) {
            return false;
        }

        return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]+$/', $s);
    }

    /**
     * WhatsApp often wraps a Solana address across lines with a hyphen.
     */
    public static function normalizeSolanaAddressInput(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/', '', $s) ?? $s;
        if (str_contains($s, '-')) {
            $stripped = str_replace('-', '', $s);
            if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $stripped) === 1) {
                return $stripped;
            }
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $data create-order or get-order JSON
     */
    public static function extractStellarDepositAddress(array $data): ?string
    {
        $preferredKeys = [
            'stellarAddress', 'stellar_address', 'stellarDepositAddress', 'stellar_deposit_address',
            'depositAddress', 'deposit_address', 'payInAddress', 'pay_in_address', 'payinAddress',
            'temporaryWallet', 'temporary_wallet', 'walletAddress', 'wallet_address',
            'fundingAddress', 'funding_address', 'cryptoDepositAddress',
        ];

        return self::findStellarAddressWithKeys($data, $preferredKeys);
    }

    /**
     * Solana public address (Centiiv per-order USDC pay-in on Solana).
     *
     * @param array<string, mixed> $data create-order or get-order JSON
     */
    public static function extractSolanaDepositAddress(array $data): ?string
    {
        $preferredKeys = [
            'solanaAddress', 'solana_address', 'solAddress', 'sol_address',
            'depositAddress', 'deposit_address', 'payInAddress', 'pay_in_address', 'payinAddress',
            'temporaryWallet', 'temporary_wallet', 'walletAddress', 'wallet_address',
            'fundingAddress', 'funding_address', 'cryptoDepositAddress',
        ];

        return self::findSolanaAddressWithKeys($data, $preferredKeys);
    }

    /**
     * EVM deposit address (Centiiv Arc USDC pay-in).
     *
     * @param array<string, mixed> $data
     */
    public static function extractEvmDepositAddress(array $data): ?string
    {
        $preferredKeys = [
            'publicAddress', 'address', 'depositAddress', 'deposit_address',
            'payInAddress', 'pay_in_address', 'payinAddress', 'walletAddress', 'wallet_address',
            'temporaryWallet', 'temporary_wallet', 'fundingAddress', 'cryptoDepositAddress',
        ];

        return self::findEvmAddressWithKeys($data, $preferredKeys);
    }

    public static function looksLikeEvmAddress(string $value): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', trim($value));
    }

    /**
     * @param list<string> $preferredKeys
     * @param array<string, mixed> $data
     */
    private static function findEvmAddressWithKeys(array $data, array $preferredKeys): ?string
    {
        foreach ($preferredKeys as $k) {
            if (empty($data[$k])) {
                continue;
            }
            $v = $data[$k];
            if (is_string($v) && self::looksLikeEvmAddress($v)) {
                return trim($v);
            }
            if (is_array($v)) {
                foreach (['publicAddress', 'address', 'walletAddress', 'depositAddress'] as $ik) {
                    if (!empty($v[$ik]) && is_string($v[$ik]) && self::looksLikeEvmAddress($v[$ik])) {
                        return trim($v[$ik]);
                    }
                }
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $found = self::findEvmAddressWithKeys($v, $preferredKeys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $preferredKeys
     */
    private static function findStellarAddressWithKeys(array $data, array $preferredKeys): ?string
    {
        foreach ($preferredKeys as $k) {
            if (empty($data[$k])) {
                continue;
            }
            $v = $data[$k];
            if (is_string($v) && self::looksLikeStellarAccountId($v)) {
                return trim($v);
            }
            // Centiiv create-order: "temporaryWallet": { "address": "G…", "expiresAt": "…" }
            if (is_array($v)) {
                foreach (['publicAddress', 'address', 'walletAddress', 'stellarAddress', 'publicKey', 'accountId', 'depositAddress'] as $ik) {
                    if (!empty($v[$ik]) && is_string($v[$ik]) && self::looksLikeStellarAccountId($v[$ik])) {
                        return trim($v[$ik]);
                    }
                }
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $f = self::findStellarAddressWithKeys($v, $preferredKeys);
                if ($f !== null) {
                    return $f;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $preferredKeys
     */
    private static function findSolanaAddressWithKeys(array $data, array $preferredKeys): ?string
    {
        $network = strtoupper(trim((string) ($data['network'] ?? $data['chain'] ?? '')));
        $isSolHint = in_array($network, ['SOL', 'SOLANA'], true);

        foreach ($preferredKeys as $k) {
            if (empty($data[$k])) {
                continue;
            }
            $v = $data[$k];
            if (is_string($v) && self::looksLikeSolanaAddress($v)) {
                return trim($v);
            }
            if (is_array($v)) {
                $innerNet = strtoupper(trim((string) ($v['network'] ?? $v['chain'] ?? '')));
                if (in_array($innerNet, ['STELLAR', 'APTOS', 'APT', 'ETH', 'SOL', 'SOLANA'], true) && $innerNet !== '') {
                    continue;
                }
                foreach (['publicAddress', 'address', 'walletAddress', 'solanaAddress', 'depositAddress'] as $ik) {
                    if (!empty($v[$ik]) && is_string($v[$ik]) && self::looksLikeSolanaAddress($v[$ik])) {
                        return trim($v[$ik]);
                    }
                }
            }
        }
        if ($isSolHint) {
            foreach (['publicAddress', 'address', 'walletAddress'] as $ik) {
                if (!empty($data[$ik]) && is_string($data[$ik]) && self::looksLikeSolanaAddress($data[$ik])) {
                    return trim($data[$ik]);
                }
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $f = self::findSolanaAddressWithKeys($v, $preferredKeys);
                if ($f !== null) {
                    return $f;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function extractOrderId(array $data): ?string
    {
        foreach (['id', 'orderId', 'order_id', 'requestId', 'request_id'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                return $data[$k];
            }
            if (!empty($data[$k]) && is_scalar($data[$k])) {
                return (string) $data[$k];
            }
        }
        $inner = $data['data'] ?? $data['order'] ?? null;
        if (is_array($inner)) {
            return self::extractOrderId($inner);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function extractOrderIdHex(array $data): ?string
    {
        foreach (['orderIdHex', 'order_id_hex'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                return $data[$k];
            }
            if (!empty($data[$k]) && is_scalar($data[$k])) {
                return (string) $data[$k];
            }
        }
        $inner = $data['data'] ?? $data['order'] ?? null;
        if (is_array($inner)) {
            return self::extractOrderIdHex($inner);
        }

        return null;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function normalizeBankList(array $decoded): array
    {
        $root = $decoded['data'] ?? $decoded['banks'] ?? $decoded;
        if (!is_array($root)) {
            return [];
        }
        if (isset($root[0]) && is_array($root[0])) {
            $list = $root;
        } elseif (isset($root['banks']) && is_array($root['banks'])) {
            $list = $root['banks'];
        } else {
            $list = [];
        }

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? $row['bankCode'] ?? $row['bank_code'] ?? '');
            $name = (string) ($row['name'] ?? $row['bankName'] ?? $row['bank_name'] ?? '');
            if ($code !== '' && $name !== '') {
                $out[] = ['code' => self::normalizeCentiivBankCode($code), 'name' => $name];
            }
        }

        return $out;
    }

    /**
     * @param list<array{code: string, name: string}> $banks
     */
    public static function resolveBankCode(string $userBankName, array $banks): ?string
    {
        $needle = self::normBankToken($userBankName);
        if ($needle === '') {
            return null;
        }

        // Special alias map for non-NIP / mobile money & regional banks
        $specialBanks = [
            'mpesa' => 'MPESA',
            'm-pesa' => 'MPESA',
            'safaricom' => 'MPESA',
            'equity' => 'EQUITY',
            'equitybank' => 'EQUITY',
            'kcb' => 'KCB',
            'kcbbank' => 'KCB',
            'coop' => 'COOP',
            'cooperative' => 'COOP',
            'airtel' => 'AIRTEL',
            'airtelmoney' => 'AIRTEL',
        ];

        if (isset($specialBanks[$needle])) {
            return $specialBanks[$needle];
        }
        foreach ($specialBanks as $key => $code) {
            if (str_contains($needle, $key)) {
                return $code;
            }
        }

        $bestCode = null;
        $bestLen = 0;
        foreach ($banks as $b) {
            $hay = self::normBankToken($b['name']);
            if ($hay === '') {
                continue;
            }
            if ($needle === $hay) {
                return self::normalizeCentiivBankCode($b['code']);
            }
            if (str_contains($hay, $needle) && strlen($needle) >= 3 && strlen($needle) > $bestLen) {
                $bestCode = $b['code'];
                $bestLen = strlen($needle);
            }
            if (str_contains($needle, $hay) && strlen($hay) >= 3 && strlen($hay) > $bestLen) {
                $bestCode = $b['code'];
                $bestLen = strlen($hay);
            }
        }

        if ($bestCode === null) {
            return null;
        }

        return self::normalizeCentiivBankCode($bestCode);
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function notConfigured(): array
    {
        return [
            'success' => false,
            'http_code' => 0,
            'data' => [],
            'raw' => null,
            'error' => 'Sendra Centiiv not configured (set SENDRA_CENTIIV_JWT or SENDRA_CENTIIV_API_KEY)',
        ];
    }

    private static function normBankToken(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/', '', $s) ?? $s;
        $s = str_replace(['bank', 'plc', 'ltd', 'limited', 'nigeria', 'ng'], '', $s);

        return $s;
    }

    /**
     * @param list<mixed> $candidates
     */
    private static function firstNumeric(array $candidates): ?float
    {
        foreach ($candidates as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_numeric($v)) {
                return (float) $v;
            }
        }

        return null;
    }

    /**
     * @param list<string> $preferredKeys
     * @param array<string, mixed> $data
     */
    private static function findAddressWithKeys(array $data, array $preferredKeys): ?string
    {
        foreach ($preferredKeys as $k) {
            if (!empty($data[$k]) && is_string($data[$k]) && str_starts_with($data[$k], '0x')) {
                return $data[$k];
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $f = self::findAddressWithKeys($v, $preferredKeys);
                if ($f !== null) {
                    return $f;
                }
            }
        }

        return null;
    }

    /**
     * Centiiv api.centiiv.io: dashboard JWT uses Bearer; client/public keys use X-API-Key (pk_* or cnt-*).
     *
     * @return list<string>
     */
    private function centiivAuthHeaders(): array
    {
        $t = $this->token;
        $mode = strtolower((string) ($_ENV['SENDRA_CENTIIV_AUTH'] ?? getenv('SENDRA_CENTIIV_AUTH') ?: ''));

        if ($mode === 'bearer') {
            return ['Authorization: Bearer ' . $t];
        }
        if ($mode === 'x_api_key' || $mode === 'x-api-key') {
            return ['X-API-Key: ' . $t];
        }

        // Explicit public SDK keys
        if (str_starts_with($t, 'pk_')) {
            return ['X-API-Key: ' . $t];
        }

        // Legacy cnt-* keys are for Direct Pay, not /public/*. If used here, Centiiv returns "use pk_*".
        if (str_starts_with($t, 'cnt-')) {
            return ['X-API-Key: ' . $t];
        }

        // Default: Bearer (dashboard JWT or opaque tokens)
        return ['Authorization: Bearer ' . $t];
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function getJson(string $path): array
    {
        $url = $this->baseUrl . $path;
        $headers = array_merge($this->centiivAuthHeaders(), [
            'Accept: application/json',
        ]);
        if ($this->platformHeader !== null) {
            $headers[] = 'X-Platform: ' . $this->platformHeader;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            error_log("Sendra Centiiv ramp GET {$path} curl_error errno={$errno} err={$err}");

            return [
                'success' => false,
                'http_code' => $code,
                'data' => [],
                'raw' => is_string($response) ? $response : null,
                'error' => "Curl error: {$err}",
            ];
        }
        curl_close($ch);

        return $this->decodeResponse($path, 'GET', $response, $code);
    }

    /**
     * GET an absolute URL (wallet rates live on api.live.centiiv.com, not the aggregator).
     *
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function getJsonAbsolute(string $url, bool $withAuth = false): array
    {
        $headers = ['Accept: application/json'];
        if ($withAuth && $this->token !== '') {
            $headers = array_merge($this->centiivAuthHeaders(), $headers);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            error_log("Sendra Centiiv ramp GET {$url} curl_error errno={$errno} err={$err}");

            return [
                'success' => false,
                'http_code' => $code,
                'data' => [],
                'raw' => is_string($response) ? $response : null,
                'error' => "Curl error: {$err}",
            ];
        }
        curl_close($ch);

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $decoded = $this->decodeResponse($path, 'GET', $response, $code);
        if ($decoded['success'] && isset($decoded['data']) && array_is_list($decoded['data'])) {
            $decoded['data'] = ['rates' => $decoded['data']];
        }

        return $decoded;
    }

    /**
     * @param array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string} $res
     */
    private function isRetryableCentiivTransportError(array $res): bool
    {
        if (!empty($res['success'])) {
            return false;
        }
        if ((int) ($res['http_code'] ?? 0) > 0) {
            // HTTP response received — do not retry order creation (may have been created).
            return false;
        }
        $err = strtolower((string) ($res['error'] ?? ''));

        return str_contains($err, 'curl error')
            && (
                str_contains($err, 'timed out')
                || str_contains($err, 'connection')
                || str_contains($err, 'resolve host')
                || str_contains($err, 'empty reply')
                || str_contains($err, 'recv failure')
            );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function postJson(string $path, array $body, int $timeoutSeconds = 45, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $path;
        $headers = array_merge($this->centiivAuthHeaders(), [
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);
        if ($this->platformHeader !== null) {
            $headers[] = 'X-Platform: ' . $this->platformHeader;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            error_log("Sendra Centiiv ramp POST {$path} curl_error errno={$errno} err={$err}");

            return [
                'success' => false,
                'http_code' => $code,
                'data' => [],
                'raw' => is_string($response) ? $response : null,
                'error' => "Curl error: {$err}",
            ];
        }
        curl_close($ch);

        return $this->decodeResponse($path, 'POST', $response, $code);
    }

    /**
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    private function decodeResponse(string $path, string $method, $response, int $code): array
    {
        $rawStr = is_string($response) ? $response : null;
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            error_log("Sendra Centiiv ramp {$method} {$path} HTTP {$code} raw: " . substr((string) $response, 0, 500));

            return [
                'success' => false,
                'http_code' => $code,
                'data' => [],
                'raw' => $rawStr,
                'error' => 'Invalid or empty JSON response',
            ];
        }

        if ($code < 200 || $code >= 300) {
            error_log("Sendra Centiiv ramp {$method} {$path} HTTP {$code}: " . json_encode($decoded));

            return [
                'success' => false,
                'http_code' => $code,
                'data' => $decoded,
                'raw' => $rawStr,
                'error' => $this->extractErrorMessage($decoded),
            ];
        }

        return [
            'success' => true,
            'http_code' => $code,
            'data' => $decoded,
            'raw' => $rawStr,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractErrorMessage(array $decoded): ?string
    {
        $base = null;
        foreach (['message', 'error', 'detail'] as $k) {
            if (isset($decoded[$k]) && is_string($decoded[$k]) && $decoded[$k] !== '') {
                $base = $decoded[$k];
                break;
            }
        }

        $details = $decoded['details'] ?? null;
        if (is_array($details) && $details !== []) {
            $parts = [];
            foreach ($details as $item) {
                if (is_string($item) && $item !== '') {
                    $parts[] = $item;
                }
            }
            if ($parts !== []) {
                $detailStr = implode('; ', $parts);
                if ($base !== null && $base !== '') {
                    return $base . ' (' . $detailStr . ')';
                }

                return $detailStr;
            }
        }

        if ($base !== null) {
            return $base;
        }

        $inner = $decoded['data'] ?? null;
        if (is_array($inner)) {
            $nested = $this->extractErrorMessage($inner);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * Logistics Direct Pay uses https://api.centiiv.com/api/v1.
     * Ramps (quote/order/banking) use https://api.centiiv.io with paths /public/*, /banking/*, /requests/*.
     */
    private static function normalizeAggregatorBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return 'https://api.centiiv.io';
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'https://api.centiiv.io';
        }

        $host = strtolower((string) $parts['host']);
        if ($host === 'api.centiiv.com') {
            error_log(
                'SendraCentiivRampService: SENDRA_CENTIIV_BASE_URL points at Direct Pay (api.centiiv.com). ' .
                'Using https://api.centiiv.io for the liquidity aggregator. Update .env to SENDRA_CENTIIV_BASE_URL=https://api.centiiv.io'
            );

            return 'https://api.centiiv.io';
        }

        if ($host === 'api.centiiv.io') {
            $path = $parts['path'] ?? '';
            if ($path !== '' && $path !== '/') {
                error_log(
                    'SendraCentiivRampService: aggregator base should be https://api.centiiv.io with no path; paths are appended in code.'
                );

                return 'https://api.centiiv.io';
            }
        }

        return $url;
    }
}
