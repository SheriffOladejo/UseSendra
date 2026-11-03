<?php

namespace App\Services;

/**
 * Authenticated Aptos Labs fullnode GraphQL + REST (Geomi API key).
 *
 * Mirrors aptos-service aptosClient.ts: Authorization Bearer + optional Origin for AG- keys.
 */
class AptosLabsClient
{
    private string $networkSlug;

    private string $apiKey;

    private string $geomiOrigin;

    private string $graphqlUrl;

    private string $restBase;

    public function __construct()
    {
        $net = strtolower(trim((string) (
            $_ENV['APTOS_EXPLORER_NETWORK']
            ?? getenv('APTOS_EXPLORER_NETWORK')
            ?: $_ENV['NODIT_APTOS_NETWORK']
            ?? getenv('NODIT_APTOS_NETWORK')
            ?: 'testnet'
        )));
        $this->networkSlug = $net === 'mainnet' ? 'mainnet' : 'testnet';

        $this->apiKey = trim((string) ($_ENV['APTOS_NODE_API_KEY'] ?? getenv('APTOS_NODE_API_KEY') ?: ''));
        $this->geomiOrigin = trim((string) (
            $_ENV['APTOS_GEOMI_ORIGIN'] ?? getenv('APTOS_GEOMI_ORIGIN') ?: 'https://usesendra.com'
        ));

        $override = trim((string) ($_ENV['APTOS_GRAPHQL_URL'] ?? getenv('APTOS_GRAPHQL_URL') ?: ''));
        if ($override !== '') {
            $this->graphqlUrl = $override;
        } else {
            $this->graphqlUrl = $this->networkSlug === 'mainnet'
                ? 'https://api.mainnet.aptoslabs.com/v1/graphql'
                : 'https://api.testnet.aptoslabs.com/v1/graphql';
        }

        $this->restBase = $this->networkSlug === 'mainnet'
            ? 'https://api.mainnet.aptoslabs.com/v1'
            : 'https://api.testnet.aptoslabs.com/v1';
    }

    public function getGraphqlUrl(): string
    {
        return $this->graphqlUrl;
    }

    public function getRestBase(): string
    {
        return $this->restBase;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @return list<string>
     */
    public function jsonAuthHeaders(): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($this->bearerHeaders() as $h) {
            $headers[] = $h;
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function bearerHeaders(): array
    {
        if ($this->apiKey === '') {
            return [];
        }

        $headers = ['Authorization: Bearer ' . $this->apiKey];
        if (str_starts_with($this->apiKey, 'AG-') && $this->geomiOrigin !== '') {
            $headers[] = 'Origin: ' . $this->geomiOrigin;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{http_code: int, body: string, curl_error: string|null}
     */
    public function graphql(string $query, array $variables = [], int $timeoutSeconds = 30): array
    {
        return $this->postJson(
            $this->graphqlUrl,
            ['query' => $query, 'variables' => $variables],
            $this->jsonAuthHeaders(),
            $timeoutSeconds
        );
    }

    /**
     * @return array{http_code: int, body: string, curl_error: string|null}
     */
    public function restGet(string $path, int $timeoutSeconds = 10): array
    {
        $url = rtrim($this->restBase, '/') . '/' . ltrim($path, '/');
        $headers = array_merge(['Accept: application/json'], $this->bearerHeaders());

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        ]);
        $body = curl_exec($ch);
        $curlError = curl_errno($ch) ? curl_error($ch) : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : '',
            'curl_error' => $curlError,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $headers
     * @return array{http_code: int, body: string, curl_error: string|null}
     */
    private function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        ]);
        $body = curl_exec($ch);
        $curlError = curl_errno($ch) ? curl_error($ch) : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : '',
            'curl_error' => $curlError,
        ];
    }
}
