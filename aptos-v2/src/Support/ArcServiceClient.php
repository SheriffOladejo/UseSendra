<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * arc-service HTTP client (create wallet, USDC balance, transfer).
 */
final class ArcServiceClient
{
    public function baseUrl(): string
    {
        $url = trim((string) ($_ENV['ARC_SERVICE_URL'] ?? getenv('ARC_SERVICE_URL') ?: 'http://127.0.0.1:3003'));

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
        return $this->postJson($this->baseUrl() . '/accounts/create', ['phone_number' => $phone], 20);
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(string $arcAddress): array
    {
        return $this->postJson($this->baseUrl() . '/accounts/balance', ['arc_address' => $arcAddress], 20);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, int $timeoutSeconds = 90): array
    {
        return $this->postJson($this->baseUrl() . '/transactions/execute', $payload, $timeoutSeconds);
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
                'error' => $curlErr ? 'Arc service unreachable' : 'Invalid response from arc-service',
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
