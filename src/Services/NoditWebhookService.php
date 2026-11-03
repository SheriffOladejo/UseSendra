<?php

namespace App\Services;

/**
 * Per-wallet Nodit Classic webhooks for inbound USDC/USDT deposits.
 *
 * - Circle USDC (faucet + mainnet): stablecoin::Deposit filtered by store_owner
 * - USDT / generic FA: fungible_asset::Deposit filtered by primary FA store
 *
 * @see https://developer.nodit.io/en/api/webhook-transaction/
 */
class NoditWebhookService
{
    private const FA_DEPOSIT_EVENT = '0x1::fungible_asset::Deposit';

    private const STABLECOIN_DEPOSIT_EVENT =
        '0x72d1e6aa6a648a3afc5d45d8d66b353f1e1837a728c0813beec77f28a697fa7a::stablecoin::Deposit';

    private const MODULE_EVENT_ACCOUNT = '0x0';

    /** @var array<string, string> */
    private const TESTNET_METADATA = [
        'USDC' => '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832',
        'USDT' => '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a',
    ];

    /** @var array<string, string> */
    private const MAINNET_METADATA = [
        'USDC' => '0xbae207659db88bea0cbead6da0ed00aac12edcdda169e591cd41c94180b46f3b',
        'USDT' => '0x357b0b74bc833e95a115ad22604854d6b0fca151cecd94111770e5d6ffc9dc2b',
    ];

    private string $apiKey;

    private string $listenerUrl;

    private string $networkSlug;

    private string $aptosApiBase;

  /** @var string|null */
    private ?string $lastError = null;

    public function __construct()
    {
        $this->apiKey = trim((string) ($_ENV['NODIT_API_KEY'] ?? getenv('NODIT_API_KEY') ?: ''));
        $this->listenerUrl = trim((string) ($_ENV['NODIT_WEBHOOK_LISTENER_URL'] ?? getenv('NODIT_WEBHOOK_LISTENER_URL') ?: ''));
        if ($this->listenerUrl === '') {
            $base = rtrim((string) ($_ENV['SENDRA_PUBLIC_BASE_URL'] ?? getenv('SENDRA_PUBLIC_BASE_URL') ?: 'https://usesendra.com'), '/');
            $this->listenerUrl = $base . '/nodit.php';
        }

        $net = strtolower(trim((string) (
            $_ENV['NODIT_APTOS_NETWORK']
            ?? getenv('NODIT_APTOS_NETWORK')
            ?: $_ENV['APTOS_EXPLORER_NETWORK']
            ?? getenv('APTOS_EXPLORER_NETWORK')
            ?: 'testnet'
        )));
        $this->networkSlug = ($net === 'mainnet') ? 'mainnet' : 'testnet';
        $this->aptosApiBase = $this->networkSlug === 'mainnet'
            ? 'https://api.mainnet.aptoslabs.com/v1'
            : 'https://api.testnet.aptoslabs.com/v1';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->listenerUrl !== '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Returns null when Aptos Webhook API is reachable; otherwise a human-readable error.
     */
    public function checkAptosWebhookApi(): ?string
    {
        if (!$this->isConfigured()) {
            return 'NODIT_API_KEY or NODIT_WEBHOOK_LISTENER_URL is missing in .env';
        }

        $ch = curl_init($this->apiBase());
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $this->apiKey,
                'accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            return null;
        }

        if ($code === 404) {
            $indexer = new NoditIndexerDepositService();
            $gqlOk = $indexer->isConfigured() && $indexer->checkConnection() === null;

            return 'Nodit Aptos Webhook REST returned HTTP 404 (your API key is still valid — '
                . 'Indexer GraphQL ' . ($gqlOk ? 'works' : 'failed') . ' with the same key). '
                . 'On Starter/free plans the console URL is correct but programmatic '
                . 'POST/GET to /v1/aptos/{network}/webhooks often returns 404 anyway. '
                . 'Use your one console webhook for push, and/or run: '
                . 'php aptos_indexer_webhook.php (cron) for per-wallet deposit polling via Nodit GraphQL.';
        }

        return "Nodit Aptos webhook API check failed (HTTP {$code}): " . substr((string) $raw, 0, 300);
    }

    /**
     * @return array<string, string>|null symbol => subscriptionId
     */
    public function registerWalletDeposits(string $aptosAddress, ?string $phoneForDescription = null): ?array
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'NODIT_API_KEY or listener URL not configured';

            return null;
        }

        $apiErr = $this->checkAptosWebhookApi();
        if ($apiErr !== null) {
            $this->lastError = $apiErr;

            return null;
        }

        $owner = $this->addressForEventData($aptosAddress);
        $metadata = $this->metadataTable();
        $subs = [];
        $label = $phoneForDescription !== null && $phoneForDescription !== ''
            ? "Sendra {$phoneForDescription}"
            : 'Sendra wallet';

        // Circle USDC (incl. testnet faucet): stablecoin::Deposit + store_owner
        $usdcStable = $this->createTransactionWebhook(
            self::STABLECOIN_DEPOSIT_EVENT,
            ['store_owner' => $owner],
            "{$label} USDC stablecoin deposit"
        );
        if ($usdcStable !== null) {
            $subs['USDC'] = $usdcStable;
        }

        // USDT: fungible_asset::Deposit scoped to primary FA store (skip if no store yet)
        $usdtMeta = $metadata['USDT'] ?? null;
        if ($usdtMeta !== null) {
            $usdtStore = $this->resolvePrimaryFaStore($this->normalizeAddress($aptosAddress), $usdtMeta);
            if ($usdtStore !== null) {
                $usdtSub = $this->createTransactionWebhook(
                    self::FA_DEPOSIT_EVENT,
                    ['store' => $this->storeForEventData($usdtStore)],
                    "{$label} USDT FA deposit"
                );
                if ($usdtSub !== null) {
                    $subs['USDT'] = $usdtSub;
                }
            }
        }

        if ($subs === []) {
            if ($this->lastError === null) {
                $this->lastError = 'Nodit did not return subscriptionId for any webhook';
            }

            return null;
        }

        return $subs;
    }

    /**
     * @param array<string, string>|string|null $existing
     */
    public function deleteWalletSubscriptions($existing): void
    {
        if (!$this->isConfigured() || $existing === null || $existing === '') {
            return;
        }

        $map = is_string($existing) ? json_decode($existing, true) : $existing;
        if (!is_array($map)) {
            return;
        }

        foreach ($map as $subId) {
            if (is_string($subId) && $subId !== '') {
                $this->deleteWebhook($subId);
            }
        }
    }

    /**
     * @param array<string, mixed> $userRow
     * @return array<string, string>|null
     */
    public function syncUserRow(array $userRow): ?array
    {
        $address = trim((string) ($userRow['aptos_address'] ?? ''));
        if ($address === '') {
            $this->lastError = 'missing aptos_address';

            return null;
        }

        $this->deleteWalletSubscriptions($userRow['nodit_webhook_subscriptions'] ?? null);

        return $this->registerWalletDeposits($address, (string) ($userRow['phone_number'] ?? ''));
    }

    private function metadataTable(): array
    {
        return $this->networkSlug === 'mainnet' ? self::MAINNET_METADATA : self::TESTNET_METADATA;
    }

    private function apiBase(): string
    {
        return "https://web3.nodit.io/v1/aptos/{$this->networkSlug}/webhooks";
    }

    /**
     * @param array<string, string> $eventData
     */
    private function createTransactionWebhook(string $eventType, array $eventData, string $description): ?string
    {
        $body = [
            'eventType' => 'TRANSACTION',
            'description' => $description,
            'notification' => [
                'webhookUrl' => $this->listenerUrl,
            ],
            'condition' => [
                'eventType' => $eventType,
                'eventAccountAddress' => self::MODULE_EVENT_ACCOUNT,
                'eventData' => $eventData,
            ],
        ];

        $isInstant = strtolower(trim((string) ($_ENV['NODIT_WEBHOOK_INSTANT'] ?? getenv('NODIT_WEBHOOK_INSTANT') ?: '0')));
        if (in_array($isInstant, ['1', 'true', 'yes', 'on'], true)) {
            $body['isInstant'] = true;
        }

        $res = $this->request('POST', $this->apiBase(), $body);
        if ($res === null || empty($res['subscriptionId'])) {
            return null;
        }

        return (string) $res['subscriptionId'];
    }

    private function deleteWebhook(string $subscriptionId): void
    {
        $url = $this->apiBase() . '/' . rawurlencode($subscriptionId);
        $this->request('DELETE', $url, null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $url, ?array $body): ?array
    {
        $ch = curl_init($url);
        $headers = [
            'X-API-KEY: ' . $this->apiKey,
            'accept: application/json',
            'content-type: application/json',
        ];
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $this->lastError = 'curl: ' . curl_error($ch);
            curl_close($ch);

            return null;
        }
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($code >= 400) {
            $msg = is_array($decoded)
                ? ($decoded['message'] ?? $decoded['code'] ?? $raw)
                : $raw;
            $this->lastError = "Nodit HTTP {$code}: {$msg}";
            error_log('NoditWebhookService: ' . $this->lastError . ' body=' . json_encode($body));

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function resolvePrimaryFaStore(string $owner, string $metadata): ?string
    {
        $payload = json_encode([
            'function' => '0x1::primary_fungible_store::primary_store_address',
            'type_arguments' => ['0x1::fungible_asset::Metadata'],
            'arguments' => [$owner, $metadata],
        ]);

        $ch = curl_init($this->aptosApiBase . '/view');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data[0]) || !is_string($data[0])) {
            return null;
        }

        return $data[0];
    }

    /** Wallet address as emitted in stablecoin::Deposit store_owner (no 64-char padding). */
    private function addressForEventData(string $address): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', trim($address)) ?? '');
        if ($hex === '' || !ctype_xdigit($hex)) {
            return strtolower(trim($address));
        }

        return '0x' . $hex;
    }

    /** FA store address as emitted in fungible_asset::Deposit data.store. */
    private function storeForEventData(string $store): string
    {
        return $this->addressForEventData($store);
    }

    private function normalizeAddress(string $address): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', trim($address)) ?? '');
        if ($hex === '' || !ctype_xdigit($hex)) {
            return strtolower(trim($address));
        }

        return '0x' . str_pad($hex, 64, '0', STR_PAD_LEFT);
    }
}
