<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * aptos-service HTTP client (gasless transfer, auth headers, explorer URLs).
 */
final class AptosServiceClient
{
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

    public function usesGaslessMethod2(): bool
    {
        $method2 = strtolower(trim((string) ($_ENV['APTOS_SMOOTHSEND_METHOD2'] ?? getenv('APTOS_SMOOTHSEND_METHOD2') ?: '0')));
        if (!in_array($method2, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        return !$this->usesFrameworkTransfer();
    }

    public function usesFrameworkTransfer(): bool
    {
        $explicit = strtolower(trim((string) ($_ENV['APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER'] ?? getenv('APTOS_USE_APTOS_ACCOUNT_FUNGIBLE_TRANSFER') ?: '')));
        if ($explicit !== '') {
            return in_array($explicit, ['1', 'true', 'yes', 'on'], true);
        }
        $net = strtolower(trim((string) (
            $_ENV['APTOS_EXPLORER_NETWORK']
            ?? getenv('APTOS_EXPLORER_NETWORK')
            ?: $_ENV['NODIT_APTOS_NETWORK']
            ?? getenv('NODIT_APTOS_NETWORK')
            ?: 'mainnet'
        )));

        return $net !== 'mainnet';
    }

    /**
     * @return array<string, mixed>
     */
    public function createAccount(string $phone): array
    {
        return $this->postJson('http://127.0.0.1:3001/accounts/create', ['phone_number' => $phone], 15);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function executeTransfer(array $payload, int $timeoutSeconds = 30): array
    {
        return $this->postJson('http://127.0.0.1:3001/transactions/execute', $payload, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function panoraSwap(array $payload, int $timeoutSeconds = 90): array
    {
        return $this->postJson('http://127.0.0.1:3001/transactions/panora-swap', $payload, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function executeGasless(array $payload, int $timeoutSeconds = 45): array
    {
        $ch = curl_init('http://127.0.0.1:3001/transactions/execute-gasless');
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
                'error' => $curlErr ? 'Service unreachable' : 'Invalid response from aptos-service',
                'raw' => (string) $rawResponse,
                'http_code' => $httpCode,
                'curl_errno' => $curlNo,
            ];
        }

        $decoded['http_code'] = $httpCode;
        $decoded['curl_errno'] = $curlNo;
        if ($curlErr !== null) {
            $decoded['curl_error'] = $curlErr;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $executeResult
     */
    public function explorerUrlForTransaction(array $executeResult, string $txHash): string
    {
        $u = $executeResult['explorer_url'] ?? null;
        if (is_string($u) && $u !== '') {
            return $u;
        }

        $net = strtoupper(trim((string) ($_ENV['APTOS_EXPLORER_NETWORK'] ?? getenv('APTOS_EXPLORER_NETWORK') ?: 'MAINNET')));
        $base = 'https://explorer.aptoslabs.com/txn/' . ltrim($txHash, " \t\n\r\0\x0B");
        if ($net === 'MAINNET' || $net === '') {
            return $base;
        }
        if ($net === 'TESTNET') {
            return $base . '?network=testnet';
        }
        if ($net === 'DEVNET') {
            return $base . '?network=devnet';
        }

        return $base;
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
                'error' => $curlErr ? 'Service unreachable' : 'Invalid response from aptos-service',
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
