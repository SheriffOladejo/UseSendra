<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * Reads USDC/USDT balances via aptos-service (same path as legacy MessageController).
 */
final class AptosBalanceService
{
    /**
     * @return array{APT: string, USDC: string, USDT: string}|null
     */
    public function fetch(string $walletAddress): ?array
    {
        $ch = curl_init('http://127.0.0.1:3001/accounts/balance');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['aptos_address' => $walletAddress]),
            CURLOPT_HTTPHEADER => $this->authHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            error_log('AptosBalanceService curl: ' . curl_error($ch));
        }
        curl_close($ch);

        if (!is_string($raw) || $http < 200 || $http >= 300) {
            error_log('AptosBalanceService: HTTP ' . $http . ' ' . substr((string) $raw, 0, 300));

            return null;
        }

        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['success']) || !isset($j['balances']) || !is_array($j['balances'])) {
            return null;
        }

        $b = $j['balances'];

        return [
            'APT' => number_format((float) ($b['APT'] ?? 0), 4),
            'USDC' => number_format((float) ($b['USDC'] ?? 0), 2),
            'USDT' => number_format((float) ($b['USDT'] ?? 0), 2),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function authHeaders(): array
    {
        $secret = trim((string) ($_ENV['INTERNAL_API_SECRET'] ?? getenv('INTERNAL_API_SECRET') ?: ''));

        return [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . $secret,
        ];
    }
}
