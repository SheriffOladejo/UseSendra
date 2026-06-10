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
        if ($toAsset !== 'NGN') {
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
            ? (int) ($_ENV['SENDRA_CENTIIV_ORDER_RETRIES_SELL'] ?? getenv('SENDRA_CENTIIV_ORDER_RETRIES_SELL') ?: 2)
            : (int) ($_ENV['SENDRA_CENTIIV_ORDER_RETRIES'] ?? getenv('SENDRA_CENTIIV_ORDER_RETRIES') ?: 3);
        $maxAttempts = max(1, min(4, $defaultRetries));
        $timeout = max(30, min(120, (int) ($_ENV['SENDRA_CENTIIV_ORDER_TIMEOUT'] ?? getenv('SENDRA_CENTIIV_ORDER_TIMEOUT') ?: 60)));

        $last = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $last = $this->postJson('/public/order', $merged, $timeout);
            if (!empty($last['success'])) {
                return $last;
            }
            if ($this->isRetryableCentiivTransportError($last) && $waPhone !== '') {
                usleep(2500000);
                $meta = is_array($merged['metadata'] ?? null) ? $merged['metadata'] : [];
                $isOnramp = ($meta['sendra_ramp_kind'] ?? '') === 'buy'
                    || (strtoupper((string) ($merged['fromAsset'] ?? '')) === 'NGN'
                        && strtoupper((string) ($merged['toAsset'] ?? '')) !== 'NGN');
                $cached = $isOnramp
                    ? self::pullRecentOnrampOrderCreatedForPhone($waPhone)
                    : self::pullRecentOfframpOrderCreatedForPhone($waPhone);
                if ($cached !== null) {
                    error_log('Sendra Centiiv: using order.created webhook after POST /public/order timeout for ' . $waPhone);

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
                'Sendra Centiiv ramp POST /public/order transport retry '
                . $attempt . '/' . $maxAttempts . ': ' . ($last['error'] ?? 'unknown')
            );
            usleep(1500000 * $attempt);
        }

        return $last ?? $this->notConfigured();
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
        if (is_string($desc) && preg_match('/\bSendra\s+WhatsApp\s+sell\s+(\d{10,20})\b/i', $desc, $m) === 1) {
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
    public static function pullRecentOfframpOrderCreatedForPhone(string $waPhone, int $maxAgeSeconds = 180): ?array
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
            @unlink($path);

            return null;
        }
        @unlink($path);

        return $decoded['order'];
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
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function getQuote(array $body): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
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
    public static function summarizeQuoteNgn(array $data, ?float $fromAssetAmountForTotalEstimate = null): array
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

        $rateHint = $rate !== null ? ('≈ ₦' . number_format($rate, 2, '.', ',') . ' / unit') : null;
        $feeHint = $fee !== null ? ('Fee (est.): ₦' . number_format($fee, 2, '.', ',')) : null;

        return [
            'receive_ngn' => $receive,
            'receive_ngn_display' => $receiveDisplay,
            'rate_hint' => $rateHint,
            'fee_hint' => $feeHint,
        ];
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
        if ($targetNgn <= 0) {
            return null;
        }
        $probeUsdc = 1.0;
        $probe = $this->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $probeUsdc,
        ]);
        if (!$probe['success']) {
            $minUsdc = null;
            if (is_string($probe['error'] ?? null)) {
                if (preg_match('/minimum transaction amount of\s*([0-9]+(?:\.[0-9]+)?)/i', $probe['error'], $m)) {
                    $minUsdc = (float) $m[1];
                }
            }

            return $minUsdc !== null ? ['centiiv_min_usdc' => $minUsdc] : null;
        }
        $ngnPerUsdc = self::ngnPerUsdcFromQuoteData($probe['data'], $probeUsdc);
        if ($ngnPerUsdc === null || $ngnPerUsdc <= 0) {
            return null;
        }

        $usdc = $targetNgn / $ngnPerUsdc;
        $maxAdjust = 2;
        for ($adj = 0; $adj <= $maxAdjust; $adj++) {
            if ($usdc < 0.5) {
                $minQ = $this->getQuote(['fromAsset' => 'USDC', 'toAsset' => 'NGN', 'amount' => 0.5]);
                if (!$minQ['success']) {
                    $minUsdc = self::parseMinUsdcFromQuoteError($minQ['error'] ?? null);

                    return $minUsdc !== null ? ['centiiv_min_usdc' => $minUsdc] : null;
                }
                $minSum = self::summarizeQuoteNgn($minQ['data'], 0.5);
                $minRecv = (float) ($minSum['receive_ngn'] ?? 0);
                if ($minRecv + 1e-6 < $targetNgn) {
                    return ['centiiv_min_usdc' => 0.5];
                }
                $usdc = 0.5;
            }

            $usdcStr = self::formatUsdcAmount($usdc);
            $confirm = $this->getQuote([
                'fromAsset' => 'USDC',
                'toAsset' => 'NGN',
                'amount' => (float) $usdcStr,
            ]);
            if (!$confirm['success']) {
                $minUsdc = self::parseMinUsdcFromQuoteError($confirm['error'] ?? null);
                if ($minUsdc !== null) {
                    $usdc = max($usdc, $minUsdc);

                    continue;
                }

                return null;
            }
            $sum = self::summarizeQuoteNgn($confirm['data'], (float) $usdcStr);
            $recv = (float) ($sum['receive_ngn'] ?? 0);
            if ($recv + 1e-9 >= $targetNgn - $toleranceNgn) {
                return [
                    'usdc_amount' => (float) $usdcStr,
                    'usdc_amount_str' => $usdcStr,
                    'sum' => $sum,
                ];
            }
            $shortfallNgn = $targetNgn - $recv;
            if ($shortfallNgn > 0 && $ngnPerUsdc > 0) {
                $usdc += ($shortfallNgn / $ngnPerUsdc) * 1.002;
            } else {
                $usdc *= 1.005;
            }
        }

        return null;
    }

    /**
     * @return ?float
     */
    private static function parseMinUsdcFromQuoteError(?string $error): ?float
    {
        if ($error === null || $error === '') {
            return null;
        }
        if (!preg_match('/minimum transaction amount of\s*([0-9]+(?:\.[0-9]+)?)/i', $error, $m)) {
            return null;
        }
        $v = (float) $m[1];

        return $v > 0 ? $v : null;
    }

    public static function formatUsdcAmountForApi(float $usdc): string
    {
        return self::formatUsdcAmount($usdc);
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
            $nested['amountOut'] ?? null,
            $toBlock['amount'] ?? null,
            $toBlock['value'] ?? null,
            $data['receiveAmount'] ?? null,
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

        if ($receive === null && $rate !== null && $fromNgnAmountForTotalEstimate !== null && $fromNgnAmountForTotalEstimate > 0) {
            $receive = round($fromNgnAmountForTotalEstimate / $rate, 6);
        }

        $receiveDisplay = $receive !== null
            ? rtrim(rtrim(number_format($receive, 6, '.', ''), '0'), '.')
            : '—';

        $rateHint = $rate !== null ? ('≈ ' . number_format($rate, 2, '.', ',') . ' NGN / unit') : null;
        $feeHint = $fee !== null ? ('Fee (est.): ₦' . number_format($fee, 2, '.', ',')) : null;

        return [
            'receive_crypto' => $receive,
            'receive_crypto_display' => $receiveDisplay,
            'rate_hint' => $rateHint,
            'fee_hint' => $feeHint,
        ];
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
        if ($network !== '' && in_array($network, ['STELLAR', 'APTOS', 'APT', 'ETH', 'SOL'], true)) {
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
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
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
    private function postJson(string $path, array $body, int $timeoutSeconds = 45): array
    {
        $url = $this->baseUrl . $path;
        $headers = array_merge($this->centiivAuthHeaders(), [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
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
