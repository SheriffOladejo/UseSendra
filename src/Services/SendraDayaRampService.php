<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sendra → Daya Business API (NGN virtual accounts, rates, customers).
 *
 * Env: SENDRA_DAYA_API_KEY, SENDRA_DAYA_BASE_URL, SENDRA_DAYA_WEBHOOK_SECRET.
 *
 * @see https://docs.daya.co/
 */
final class SendraDayaRampService
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $this->apiKey = trim((string) ($apiKey ?? $_ENV['SENDRA_DAYA_API_KEY'] ?? getenv('SENDRA_DAYA_API_KEY') ?: ''));
        $rawBase = trim((string) ($baseUrl ?? $_ENV['SENDRA_DAYA_BASE_URL'] ?? getenv('SENDRA_DAYA_BASE_URL') ?: ''));
        if ($rawBase === '') {
            $rawBase = $this->looksLikeSandboxKey($this->apiKey)
                ? 'https://api.sandbox.daya.co'
                : 'https://api.daya.co';
        }
        $this->baseUrl = rtrim($rawBase, '/');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function isSandbox(): bool
    {
        return str_contains(strtolower($this->baseUrl), 'sandbox')
            || $this->looksLikeSandboxKey($this->apiKey);
    }

    /**
     * @return array{success: bool, error?: string, chains?: list<array<string, mixed>>}
     */
    public function listSupportedChains(): array
    {
        $res = $this->request('GET', '/v1/supported-chains', null, null, null, false);
        if (!$res['success']) {
            return $res;
        }
        $data = $res['data'] ?? null;
        $chains = [];
        if (is_array($data)) {
            if (isset($data['data']) && is_array($data['data'])) {
                $chains = $data['data'];
            } elseif (array_is_list($data)) {
                $chains = $data;
            }
        }

        return ['success' => true, 'chains' => $chains];
    }

    /**
     * @return array{ok: bool, chain?: string, asset?: string, withdraw_enabled?: bool, deposit_enabled?: bool, error?: string, token?: array<string, mixed>}
     */
    public function chainAssetSupport(string $chain, string $asset): array
    {
        $chain = strtoupper(trim($chain));
        $asset = strtoupper(trim($asset));
        $listed = $this->listSupportedChains();
        if (!$listed['success']) {
            return ['ok' => false, 'error' => (string) ($listed['error'] ?? 'supported_chains_failed')];
        }

        foreach ($listed['chains'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = strtoupper((string) ($entry['chain'] ?? ''));
            if ($id !== $chain) {
                continue;
            }
            $tokens = is_array($entry['tokens'] ?? null) ? $entry['tokens'] : [];
            foreach ($tokens as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (strtoupper((string) ($token['symbol'] ?? '')) !== $asset) {
                    continue;
                }

                return [
                    'ok' => true,
                    'chain' => $chain,
                    'asset' => $asset,
                    'withdraw_enabled' => !empty($token['withdraw_enabled']),
                    'deposit_enabled' => !empty($token['deposit_enabled']),
                    'token' => $token,
                ];
            }

            return ['ok' => false, 'error' => 'asset_not_listed', 'chain' => $chain, 'asset' => $asset];
        }

        return ['ok' => false, 'error' => 'chain_not_listed', 'chain' => $chain, 'asset' => $asset];
    }

    /**
     * @return array{success: bool, error?: string, rate?: array<string, mixed>}
     */
    public function getBuyRate(string $toAsset = 'USDT'): array
    {
        return $this->getRate($toAsset, 'BUY');
    }

    /**
     * Firm FX quote. BUY = NGN→crypto, SELL = crypto→NGN.
     *
     * @return array{success: bool, error?: string, rate?: array<string, mixed>}
     */
    public function getRate(string $asset = 'USDC', string $side = 'BUY'): array
    {
        $asset = strtoupper(trim($asset));
        if ($asset === 'USDT0') {
            $asset = 'USDT';
        }
        $side = strtoupper(trim($side)) === 'SELL' ? 'SELL' : 'BUY';
        $res = $this->request('GET', '/v1/rates', [
            'from' => 'NGN',
            'to' => $asset,
            'side' => $side,
        ]);
        if (!$res['success']) {
            return $res;
        }
        $rate = $res['data'] ?? null;
        if (!is_array($rate) || empty($rate['rate_id'])) {
            return ['success' => false, 'error' => 'rate_unavailable'];
        }

        return ['success' => true, 'rate' => $rate];
    }

    /**
     * Live Business API fee schedule (`GET /v1/fees`).
     *
     * @return array{success: bool, error?: string, fees?: array<string, mixed>}
     */
    public function getFees(): array
    {
        $res = $this->request('GET', '/v1/fees');
        if (!$res['success']) {
            return $res;
        }
        $fees = $res['data'] ?? null;
        if (!is_array($fees)) {
            return ['success' => false, 'error' => 'fees_unavailable'];
        }

        return ['success' => true, 'fees' => $fees];
    }

    /**
     * @return array{success: bool, error?: string, banks?: list<array{code: string, name: string}>}
     */
    public function listBanks(): array
    {
        $res = $this->request('GET', '/v1/banks');
        if (!$res['success']) {
            return $res;
        }

        return ['success' => true, 'banks' => self::normalizeBankList($res['data'] ?? null)];
    }

    /**
     * @return array{success: bool, error?: string, account_name?: string, account_number?: string}
     */
    public function resolveBankAccount(string $bankCode, string $accountNumber): array
    {
        $res = $this->request('POST', '/v1/banks/resolve', null, [
            'bank_code' => trim($bankCode),
            'account_number' => trim($accountNumber),
        ]);
        if (!$res['success']) {
            return $res;
        }
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $name = trim((string) ($data['account_name'] ?? $data['accountName'] ?? ''));
        $number = trim((string) ($data['account_number'] ?? $accountNumber));
        if ($name === '') {
            return ['success' => false, 'error' => 'resolve_name_missing'];
        }

        return ['success' => true, 'account_name' => $name, 'account_number' => $number];
    }

    /**
     * @param mixed $payload
     * @return list<array{code: string, name: string}>
     */
    public static function normalizeBankList(mixed $payload): array
    {
        $rows = [];
        if (is_array($payload)) {
            if (isset($payload['data']) && is_array($payload['data'])) {
                $payload = $payload['data'];
            }
            if (isset($payload['banks']) && is_array($payload['banks'])) {
                $payload = $payload['banks'];
            }
            foreach ($payload as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string) ($row['code'] ?? $row['bank_code'] ?? $row['bankCode'] ?? ''));
                $name = trim((string) ($row['name'] ?? $row['bank_name'] ?? $row['bankName'] ?? ''));
                if ($code === '' || $name === '') {
                    continue;
                }
                $rows[] = ['code' => $code, 'name' => $name];
            }
        }

        return $rows;
    }

    /**
     * Common Nigerian bank nicknames → tokens to match against Daya bank names.
     *
     * @return array<string, list<string>>
     */
    public static function bankNameAliasMap(): array
    {
        return [
            'opay' => ['paycom', 'opay'],
            'palmpay' => ['palmpay'],
            'palm pay' => ['palmpay'],
            'moniepoint' => ['moniepoint', 'teamapt'],
            'monie' => ['moniepoint', 'teamapt'],
            'kuda' => ['kuda'],
            'gtb' => ['guaranty', 'gtbank', 'gtb'],
            'gtbank' => ['guaranty', 'gtbank', 'gtb'],
            'gtbank plc' => ['guaranty', 'gtbank', 'gtb'],
            'guaranty trust' => ['guaranty', 'gtbank'],
            'firstbank' => ['first bank', 'firstbank'],
            'first bank' => ['first bank', 'firstbank'],
            'uba' => ['united bank for africa', 'uba'],
            'zenith' => ['zenith'],
            'wema' => ['wema'],
            'access' => ['access bank', 'access'],
            'nombank' => ['nombank', 'nomba'],
            'nombank mfb' => ['nombank', 'nomba'],
        ];
    }

    /**
     * @param list<array{code: string, name: string}> $banks
     */
    public static function resolveBankCodeByName(string $guess, array $banks): ?string
    {
        $guess = strtolower(trim($guess));
        if ($guess === '') {
            return null;
        }
        // Strip trailing corporate suffixes so "GTBank Plc" can alias-match.
        $guessCore = trim(preg_replace('/\b(plc|ltd|limited|mfb|microfinance bank|bank)\b/i', '', $guess) ?? $guess);
        $guessCore = trim(preg_replace('/\s+/', ' ', $guessCore) ?? $guessCore);

        foreach ($banks as $b) {
            if (strtolower($b['name']) === $guess || strtolower($b['code']) === $guess) {
                return $b['code'];
            }
        }
        foreach ($banks as $b) {
            $name = strtolower($b['name']);
            if (str_contains($name, $guess) || str_contains($guess, $name)) {
                return $b['code'];
            }
        }

        $aliasMap = self::bankNameAliasMap();
        $searchTerms = $aliasMap[$guess] ?? $aliasMap[$guessCore] ?? null;
        if ($searchTerms === null && $guessCore !== '') {
            // e.g. "gtbank plc" already handled; also try first token "gtbank"
            $first = explode(' ', $guessCore, 2)[0];
            $searchTerms = $aliasMap[$first] ?? [$guessCore];
        }
        if (is_array($searchTerms)) {
            foreach ($banks as $b) {
                $name = strtolower($b['name']);
                foreach ($searchTerms as $term) {
                    $term = strtolower(trim((string) $term));
                    if ($term !== '' && str_contains($name, $term)) {
                        return $b['code'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Map a Centiiv/NIP-style bank code onto a code Daya accepts.
     * Prefer an exact Daya listing match; never invent a truncated code that Daya does not list
     * (e.g. Centiiv GTBank "000013" must not become "013").
     *
     * @param list<array{code: string, name: string}> $banks
     */
    public static function mapExternalBankCodeToDaya(string $externalCode, array $banks): ?string
    {
        $external = preg_replace('/\D+/', '', trim($externalCode)) ?? '';
        if ($external === '') {
            return null;
        }

        $byCode = [];
        foreach ($banks as $b) {
            $c = preg_replace('/\D+/', '', $b['code']) ?? '';
            if ($c !== '') {
                $byCode[$c] = $b['code'];
                $byCode[ltrim($c, '0') ?: '0'] = $b['code'];
            }
        }

        if (isset($byCode[$external])) {
            return $byCode[$external];
        }
        $stripped = ltrim($external, '0') ?: '0';
        if (isset($byCode[$stripped])) {
            return $byCode[$stripped];
        }

        // Only use last-3 when that exact short code exists in Daya's list (e.g. "000058" → "058").
        if (strlen($external) > 3) {
            $tail = substr($external, -3);
            if (isset($byCode[$tail])) {
                return $byCode[$tail];
            }
        }

        // Daya often accepts the full Centiiv/NIP code even when not in the short list (e.g. "000013").
        return $external;
    }

    /**
     * Deposit address on a CRYPTO_ADDRESS funding account.
     *
     * @param array<string, mixed> $account
     */
    public static function extractCryptoAddress(array $account): ?string
    {
        $instructions = is_array($account['instructions'] ?? null) ? $account['instructions'] : [];
        foreach ($instructions as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['address', 'deposit_address', 'wallet_address', 'account_address'] as $key) {
                $v = trim((string) ($row[$key] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }
        foreach (['address', 'deposit_address', 'wallet_address'] as $key) {
            $v = trim((string) ($account[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * Temporary NGN funding accounts require a whole-naira principal (int64).
     * A fractional amount is rejected as "invalid request body".
     * $roundUp is for crypto-target quotes so the customer still receives at least the amount they asked for.
     */
    public static function wholeNairaPrincipal(float $payNgn, bool $roundUp = false): int
    {
        if ($payNgn <= 0) {
            return 0;
        }
        $n = $roundUp
            ? (int) ceil($payNgn - 1e-6)
            : (int) round($payNgn);

        return max(0, $n);
    }

    public static function developerFeePct(): string
    {
        $raw = trim((string) ($_ENV['SENDRA_DAYA_DEVELOPER_FEE_PCT'] ?? getenv('SENDRA_DAYA_DEVELOPER_FEE_PCT') ?: '0.5'));
        if ($raw === '' || !is_numeric($raw)) {
            return '0.5';
        }
        $n = (float) $raw;
        if ($n < 0) {
            $n = 0;
        }
        if ($n > 50) {
            $n = 50;
        }

        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param array<string, mixed> $userRow
     * @return array{success: bool, error?: string, customer_id?: string}
     */
    public function ensureCustomer(string $phone, array $userRow = []): array
    {
        $email = $this->customerEmail($phone);
        $listed = $this->request('GET', '/v1/customers', ['email' => $email, 'limit' => 5]);
        if ($listed['success']) {
            $id = $this->firstCustomerId($listed['data'] ?? null);
            if ($id !== null) {
                return ['success' => true, 'customer_id' => $id];
            }
        }

        [$first, $last] = $this->customerNames($userRow);
        $created = $this->request('POST', '/v1/customers', null, [
            'email' => $email,
            'first_name' => $first,
            'last_name' => $last,
        ]);
        if ($created['success']) {
            $id = $this->extractId($created['data'] ?? null);
            if ($id !== null) {
                return ['success' => true, 'customer_id' => $id];
            }
        }

        $code = strtoupper((string) ($created['error_code'] ?? ''));
        if ($code === 'CONFLICT' || str_contains(strtolower((string) ($created['error'] ?? '')), 'already exists')) {
            $again = $this->request('GET', '/v1/customers', ['email' => $email, 'limit' => 5]);
            if ($again['success']) {
                $id = $this->firstCustomerId($again['data'] ?? null);
                if ($id !== null) {
                    return ['success' => true, 'customer_id' => $id];
                }
            }
        }

        return [
            'success' => false,
            'error' => (string) ($created['error'] ?? $listed['error'] ?? 'customer_create_failed'),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, error?: string, account?: array<string, mixed>}
     */
    public function createFundingAccount(array $body, string $idempotencyKey): array
    {
        $res = $this->request('POST', '/v1/funding-accounts', null, $body, $idempotencyKey);
        if (!$res['success']) {
            return $res;
        }
        $account = $res['data'] ?? null;
        if (!is_array($account) || empty($account['id'])) {
            return ['success' => false, 'error' => 'funding_account_invalid'];
        }

        return ['success' => true, 'account' => $account];
    }

    /**
     * @return array{success: bool, error?: string, account?: array<string, mixed>}
     */
    public function getFundingAccount(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['success' => false, 'error' => 'missing_id'];
        }
        $res = $this->request('GET', '/v1/funding-accounts/' . rawurlencode($id));
        if (!$res['success']) {
            return $res;
        }
        $account = $res['data'] ?? null;
        if (!is_array($account)) {
            return ['success' => false, 'error' => 'funding_account_invalid'];
        }

        return ['success' => true, 'account' => $account];
    }

    /**
     * @return array{success: bool, error?: string, deposits?: list<array<string, mixed>>}
     */
    public function listDeposits(?string $fundingAccountId = null, int $limit = 20): array
    {
        $query = ['limit' => max(1, min(100, $limit))];
        if ($fundingAccountId !== null && $fundingAccountId !== '') {
            $query['funding_account_id'] = $fundingAccountId;
        }
        $res = $this->request('GET', '/v1/deposits', $query);
        if (!$res['success']) {
            return $res;
        }
        $payload = $res['data'] ?? null;
        $rows = [];
        if (is_array($payload)) {
            if (isset($payload['data']) && is_array($payload['data'])) {
                $rows = $payload['data'];
            } elseif (array_is_list($payload)) {
                $rows = $payload;
            }
        }

        return ['success' => true, 'deposits' => $rows, 'raw' => $payload];
    }

    /**
     * @param array<string, mixed> $account
     * @return array{account_name: string, account_number: string, bank_name: string, bank_code?: string}|null
     */
    public static function extractActiveBankInstruction(array $account): ?array
    {
        $instructions = is_array($account['instructions'] ?? null) ? $account['instructions'] : [];
        $fallback = null;
        foreach ($instructions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $number = trim((string) ($row['account_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $parsed = [
                'account_name' => (string) ($row['account_name'] ?? 'Daya'),
                'account_number' => $number,
                'bank_name' => (string) ($row['bank_name'] ?? 'Bank'),
                'bank_code' => isset($row['bank_code']) ? (string) $row['bank_code'] : '',
            ];
            $status = strtoupper((string) ($row['status'] ?? ''));
            if ($status === 'ACTIVE' || $status === '') {
                return $parsed;
            }
            $fallback ??= $parsed;
        }

        return $fallback;
    }

    public static function webhookSecret(): string
    {
        $secrets = self::webhookSecrets();

        return $secrets[0] ?? '';
    }

    /**
     * Production and sandbox signing secrets (same Sendra URL can receive both).
     *
     * @return list<string>
     */
    public static function webhookSecrets(): array
    {
        $keys = [
            'SENDRA_DAYA_WEBHOOK_SECRET',
            'SENDRA_DAYA_SANDBOX_WEBHOOK_SECRET',
            'SENDRA_DAYA_COINS_WEBHOOK_SECRET',
        ];
        $out = [];
        foreach ($keys as $key) {
            $v = trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    public static function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, strtolower(trim($signature)))
            || hash_equals($expected, trim($signature));
    }

    public static function verifyWebhookAgainstConfiguredSecrets(string $rawBody, string $signature): bool
    {
        foreach (self::webhookSecrets() as $secret) {
            if (self::verifyWebhookSignature($rawBody, $signature, $secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function firstCustomerId(mixed $payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }
        $rows = $payload;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $rows = $payload['data'];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->extractId($row);
            if ($id !== null) {
                return $id;
            }
        }

        return $this->extractId($payload);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function extractId(mixed $row): ?string
    {
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'customer_id'] as $key) {
            $v = trim((string) ($row[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }

    public function customerEmail(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            $digits = 'unknown';
        }

        return 'sendra.' . $digits . '@customers.usesendra.com';
    }

    /**
     * @param array<string, mixed> $userRow
     * @return array{0: string, 1: string}
     */
    private function customerNames(array $userRow): array
    {
        $full = trim((string) ($userRow['kyc_full_name'] ?? $userRow['full_name'] ?? ''));
        if ($full !== '') {
            $parts = preg_split('/\s+/', $full) ?: [];
            $first = (string) ($parts[0] ?? 'Sendra');
            $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'User';

            return [mb_substr($first, 0, 100), mb_substr($last, 0, 100)];
        }

        return ['Sendra', 'User'];
    }

    private function looksLikeSandboxKey(string $key): bool
    {
        $k = strtolower($key);

        return str_starts_with($k, 'sk_sandbox_')
            || str_starts_with($k, 'daya_sk_test_')
            || str_contains($k, '_test_');
    }

    /**
     * @param array<string, scalar>|null $query
     * @param array<string, mixed>|null $body
     * @return array{success: bool, error?: string, error_code?: string, http?: int, data?: mixed}
     */
    private function request(
        string $method,
        string $path,
        ?array $query = null,
        ?array $body = null,
        ?string $idempotencyKey = null,
        bool $auth = true,
    ): array {
        $url = $this->baseUrl . $path;
        if ($query !== null && $query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($auth) {
            $headers[] = 'X-Api-Key: ' . $this->apiKey;
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return ['success' => false, 'error' => 'daya_network', 'http' => $http];
        }

        $decoded = json_decode((string) $raw, true);
        if ($http >= 200 && $http < 300) {
            return ['success' => true, 'http' => $http, 'data' => is_array($decoded) ? $decoded : []];
        }

        $err = 'daya_http_' . $http;
        $code = '';
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error)) {
                $code = (string) ($error['code'] ?? '');
                $err = (string) ($error['message'] ?? $error['details'] ?? $err);
                $validation = $error['validation'] ?? null;
            } elseif (is_string($decoded['message'] ?? null)) {
                $err = (string) $decoded['message'];
                $validation = $decoded['validation'] ?? null;
            } else {
                $validation = $decoded['validation'] ?? null;
            }
            if (is_string($validation) && $validation !== '') {
                $err .= ' — ' . $validation;
            } elseif (is_array($validation)) {
                $encoded = json_encode($validation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded) && $encoded !== '') {
                    $err .= ' — ' . $encoded;
                }
            }
        }

        error_log('Sendra Daya ' . $method . ' ' . $path . ' HTTP ' . $http . ': ' . $err);

        return ['success' => false, 'error' => $err, 'error_code' => $code, 'http' => $http, 'data' => $decoded];
    }
}
