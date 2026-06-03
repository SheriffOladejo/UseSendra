<?php

namespace App\Services;

/**
 * Poll Sendra-wallet deposits via Nodit Aptos Indexer GraphQL (works on Starter plan).
 *
 * Endpoint pattern from console: https://aptos-testnet.nodit.io/{API_KEY}/v1/graphql
 */
class NoditIndexerDepositService
{
    private const DEPOSIT_TYPE = '0x1::fungible_asset::Deposit';

    /** @var array<string, string> */
    private const TESTNET_ASSETS = [
        '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832' => 'USDC',
        '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a' => 'USDT',
    ];

    /** @var array<string, string> */
    private const MAINNET_ASSETS = [
        '0xbae207659db88bea0cbead6da0ed00aac12edcdda169e591cd41c94180b46f3b' => 'USDC',
        '0x357b0b74bc833e95a115ad22604854d6b0fca151cecd94111770e5d6ffc9dc2b' => 'USDT',
    ];

    private string $graphqlUrl;

    private string $networkSlug;

    /** @var string|null */
    private ?string $lastError = null;

    public function __construct()
    {
        $key = trim((string) ($_ENV['NODIT_API_KEY'] ?? getenv('NODIT_API_KEY') ?: ''));
        $net = strtolower(trim((string) (
            $_ENV['NODIT_APTOS_NETWORK']
            ?? getenv('NODIT_APTOS_NETWORK')
            ?: $_ENV['APTOS_EXPLORER_NETWORK']
            ?? getenv('APTOS_EXPLORER_NETWORK')
            ?: 'testnet'
        )));
        $this->networkSlug = ($net === 'mainnet') ? 'mainnet' : 'testnet';

        $override = trim((string) ($_ENV['NODIT_APTOS_GRAPHQL_URL'] ?? getenv('NODIT_APTOS_GRAPHQL_URL') ?: ''));
        if ($override !== '') {
            $this->graphqlUrl = $override;
        } elseif ($key === '') {
            $this->graphqlUrl = '';
        } else {
            $host = $this->networkSlug === 'mainnet'
                ? 'https://aptos-mainnet.nodit.io'
                : 'https://aptos-testnet.nodit.io';
            $this->graphqlUrl = "{$host}/{$key}/v1/graphql";
        }
    }

    public function isConfigured(): bool
    {
        return $this->graphqlUrl !== '';
    }

    public function getGraphqlUrl(): string
    {
        return $this->graphqlUrl;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Verify indexer responds (ledger query).
     */
    public function checkConnection(): ?string
    {
        if (!$this->isConfigured()) {
            return 'NODIT_API_KEY is not set';
        }

        $ch = curl_init($this->graphqlUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'query' => '{ ledger_infos(limit: 1) { chain_id } }',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return "Nodit GraphQL HTTP {$code}: " . substr((string) $raw, 0, 200);
        }

        $data = json_decode((string) $raw, true);
        if (!empty($data['errors'])) {
            return 'Nodit GraphQL error: ' . json_encode($data['errors']);
        }

        return null;
    }

    /**
     * @param list<string> $ownerAddresses Aptos addresses (any format)
     * @return list<array<string, mixed>>|null
     */
    public function fetchDepositsForOwners(array $ownerAddresses, int $afterVersion = 0, int $limit = 100): ?array
    {
        if (!$this->isConfigured()) {
            $this->lastError = 'Nodit GraphQL URL not configured';

            return null;
        }

        $owners = array_values(array_unique(array_filter(array_map(
            fn ($a) => $this->addressForIndexer($a),
            $ownerAddresses
        ))));

        if ($owners === []) {
            return [];
        }

        $assetTypes = array_keys($this->assetMap());
        $all = [];

        foreach (array_chunk($owners, 80) as $chunk) {
            $batch = $this->graphqlDeposits($chunk, $assetTypes, $afterVersion, $limit);
            if ($batch === null) {
                return null;
            }
            $all = array_merge($all, $batch);
        }

        usort($all, fn ($a, $b) => (int) $a['transaction_version'] <=> (int) $b['transaction_version']);

        return $this->enrichActivitiesWithTransactionMeta($all);
    }

    /**
     * Attach sender + transaction hash (GraphQL has sender; hash via REST).
     *
     * @param list<array<string, mixed>> $activities
     * @return list<array<string, mixed>>
     */
    public function enrichActivitiesWithTransactionMeta(array $activities): array
    {
        if ($activities === []) {
            return [];
        }

        $versions = array_values(array_unique(array_map(
            fn ($a) => (string) ($a['transaction_version'] ?? ''),
            $activities
        )));
        $versions = array_values(array_filter($versions));

        $senders = $this->fetchSendersByVersion($versions);
        $hashes = $this->fetchHashesByVersion($versions);

        foreach ($activities as &$row) {
            $v = (string) ($row['transaction_version'] ?? '');
            if (isset($senders[$v])) {
                $row['sender'] = $senders[$v];
            }
            if (isset($hashes[$v])) {
                $row['transaction_hash'] = $hashes[$v];
            }
        }
        unset($row);

        return $activities;
    }

    /**
     * @param list<string> $versions
     * @return array<string, string> version => sender
     */
    private function fetchSendersByVersion(array $versions): array
    {
        $out = [];
        $query = <<<'GRAPHQL'
query TxSenders($versions: [bigint!]!) {
  user_transactions(where: { version: { _in: $versions } }) {
    version
    sender
  }
}
GRAPHQL;

        foreach (array_chunk($versions, 50) as $chunk) {
            $payload = json_encode([
                'query' => $query,
                'variables' => ['versions' => array_map('intval', $chunk)],
            ]);
            $data = $this->graphqlRequest($payload);
            if ($data === null) {
                continue;
            }
            foreach ($data['data']['user_transactions'] ?? [] as $tx) {
                $v = (string) ($tx['version'] ?? '');
                $s = $tx['sender'] ?? '';
                if ($v !== '' && is_string($s) && $s !== '') {
                    $out[$v] = $s;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $versions
     * @return array<string, string> version => hash
     */
    private function fetchHashesByVersion(array $versions): array
    {
        $base = $this->networkSlug === 'mainnet'
            ? 'https://api.mainnet.aptoslabs.com/v1'
            : 'https://api.testnet.aptoslabs.com/v1';
        $out = [];

        foreach ($versions as $version) {
            $ch = curl_init("{$base}/transactions/by_version/{$version}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $tx = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($tx) && !empty($tx['hash'])) {
                $out[$version] = (string) $tx['hash'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function graphqlRequest(string $payload): ?array
    {
        $ch = curl_init($this->graphqlUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            return null;
        }

        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : null;
    }

    /** @return array<string, string> */
    public function assetMap(): array
    {
        return $this->networkSlug === 'mainnet' ? self::MAINNET_ASSETS : self::TESTNET_ASSETS;
    }

    public function currencyForAssetType(string $assetType): ?string
    {
        return $this->assetMap()[$assetType] ?? null;
    }

    /**
     * @param list<string> $owners
     * @param list<string> $assetTypes
     * @return list<array<string, mixed>>|null
     */
    private function graphqlDeposits(array $owners, array $assetTypes, int $afterVersion, int $limit): ?array
    {
        $query = <<<'GRAPHQL'
query SendraDeposits($where: fungible_asset_activities_bool_exp, $limit: Int!) {
  fungible_asset_activities(
    limit: $limit
    order_by: { transaction_version: asc }
    where: $where
  ) {
    amount
    asset_type
    transaction_version
    transaction_timestamp
    owner_address
    type
    is_transaction_success
  }
}
GRAPHQL;

        $where = [
            'asset_type' => ['_in' => $assetTypes],
            'type' => ['_eq' => self::DEPOSIT_TYPE],
            'is_transaction_success' => ['_eq' => true],
            'owner_address' => ['_in' => $owners],
            'transaction_version' => ['_gt' => (string) $afterVersion],
        ];

        $payload = json_encode([
            'query' => $query,
            'variables' => ['where' => $where, 'limit' => $limit],
        ]);

        $data = $this->graphqlRequest($payload);
        if ($data === null) {
            $this->lastError = 'GraphQL request failed';

            return null;
        }
        if (!empty($data['errors'])) {
            $this->lastError = json_encode($data['errors']);

            return null;
        }

        return $data['data']['fungible_asset_activities'] ?? [];
    }

    private function addressForIndexer(string $address): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', trim($address)) ?? '');
        if ($hex === '' || !ctype_xdigit($hex)) {
            return strtolower(trim($address));
        }

        return '0x' . $hex;
    }
}
