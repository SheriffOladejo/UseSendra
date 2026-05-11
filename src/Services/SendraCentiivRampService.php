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
     * @param array<string, mixed> $body
     * @return array{success: bool, http_code: int, data: array<string, mixed>, raw: ?string, error: ?string}
     */
    public function createConversionOrder(array $body): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        return $this->postJson('/public/order', $this->mergeRoutingHints($body));
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

        return $this->getJson('/banking/banks');
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

        $ex = $root['externalId'] ?? null;
        if (is_string($ex) && $ex !== '') {
            $b['externalId'] = $ex;
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
    private function postJson(string $path, array $body): array
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
            CURLOPT_TIMEOUT => 45,
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
        foreach (['message', 'error', 'detail'] as $k) {
            if (isset($decoded[$k]) && is_string($decoded[$k]) && $decoded[$k] !== '') {
                return $decoded[$k];
            }
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
