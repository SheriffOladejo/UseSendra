<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * stellar-service HTTP client (create C, balance, gasless SAC transfer).
 */
final class StellarServiceClient
{
    public function baseUrl(): string
    {
        $url = trim((string) ($_ENV['STELLAR_SERVICE_URL'] ?? getenv('STELLAR_SERVICE_URL') ?: 'http://127.0.0.1:3002'));

        return rtrim($url, '/');
    }

    /**
     * @return list<string>
     */
    public function authHeaders(): array
    {
        $secret = trim((string) ($_ENV['INTERNAL_API_SECRET'] ?? getenv('INTERNAL_API_SECRET') ?: ''));

        return [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . $secret,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createAccount(string $phone): array
    {
        return $this->postJson($this->baseUrl() . '/accounts/create', ['phone_number' => $phone], 90);
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(string $stellarAddress): array
    {
        return $this->postJson($this->baseUrl() . '/accounts/balance', ['stellar_address' => $stellarAddress], 20);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function executeGasless(array $payload, int $timeoutSeconds = 60): array
    {
        return $this->postJson($this->baseUrl() . '/transactions/execute-gasless', $payload, $timeoutSeconds);
    }

    /**
     * Operator G (shared receive) → user C. Used after Centiiv/indexer credits the pool.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sweepToC(array $payload, int $timeoutSeconds = 60): array
    {
        return $this->postJson($this->baseUrl() . '/transactions/sweep-to-c', $payload, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $this->authHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        $curlNo = (int) curl_errno($ch);
        $curlErr = $curlNo ? curl_error($ch) : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $rawResponse = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'error' => $curlErr ? 'Stellar service unreachable' : 'Invalid response from stellar-service',
                'raw' => (string) $rawResponse,
                'http_code' => $httpCode,
                'curl_errno' => $curlNo,
            ];
        }

        $decoded['http_code'] = $httpCode;
        $decoded['curl_errno'] = $curlNo;
        $decoded['raw'] = (string) $rawResponse;
        if ($curlErr !== null) {
            $decoded['curl_error'] = $curlErr;
        }

        return $decoded;
    }
}
