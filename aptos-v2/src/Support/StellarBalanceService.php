<?php

declare(strict_types=1);

namespace AptosV2\Support;

final class StellarBalanceService
{
    public function __construct(private StellarServiceClient $client = new StellarServiceClient())
    {
    }

    /**
     * @return array{USDC: string, USDT: string}|null
     */
    public function fetch(string $stellarAddress): ?array
    {
        $j = $this->client->balance($stellarAddress);
        if (empty($j['success']) || !isset($j['balances']) || !is_array($j['balances'])) {
            error_log('StellarBalanceService: ' . substr((string) ($j['error'] ?? $j['raw'] ?? ''), 0, 300));

            return null;
        }
        $b = $j['balances'];

        return [
            'USDC' => number_format((float) ($b['USDC'] ?? 0), 2),
            'USDT' => number_format((float) ($b['USDT'] ?? 0), 2),
        ];
    }
}
