<?php

declare(strict_types=1);

namespace AptosV2\Support;

final class ArcBalanceService
{
    public function __construct(private ArcServiceClient $client = new ArcServiceClient())
    {
    }

    /**
     * @return array{USDC: string}|null
     */
    public function fetch(string $arcAddress): ?array
    {
        $j = $this->client->balance($arcAddress);
        if (empty($j['success']) || !isset($j['balances']) || !is_array($j['balances'])) {
            error_log('ArcBalanceService: ' . substr((string) ($j['error'] ?? $j['raw'] ?? ''), 0, 300));

            return null;
        }

        return [
            'USDC' => number_format((float) ($j['balances']['USDC'] ?? 0), 2),
        ];
    }
}
