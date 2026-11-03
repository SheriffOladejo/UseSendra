<?php

declare(strict_types=1);

namespace AptosV2\Steps\Swap;

/**
 * Parses "swap 2 USDT to USDC" style messages.
 */
final class IntentParser
{
    /**
     * @return array{amount: float, from_asset: string, to_asset: string, direction: string}|null
     */
    public static function parse(string $message): ?array
    {
        $t = trim($message);
        if (!preg_match('/^(?:swap|convert)\s+/i', $t)) {
            return null;
        }

        $patterns = [
            '/^(?:swap|convert)\s+([\d,\s]+(?:\.\d+)?)\s+(usdt|usdc)\s+(?:to|for|into|→|->)\s+(usdt|usdc)\b/i',
            '/^(?:swap|convert)\s+([\d,\s]+(?:\.\d+)?)\s+(usdt|usdc)\s+(usdt|usdc)\b/i',
        ];

        foreach ($patterns as $pat) {
            if (!preg_match($pat, $t, $m)) {
                continue;
            }

            $rawAmt = str_replace([',', ' '], '', (string) $m[1]);
            if ($rawAmt === '' || !is_numeric($rawAmt)) {
                return null;
            }

            $amount = (float) $rawAmt;
            if ($amount < 0.01) {
                return null;
            }

            $from = strtoupper((string) $m[2]);
            $to = strtoupper((string) $m[3]);
            if ($from === $to) {
                return null;
            }

            $direction = \App\Services\SendraPanoraService::normalizeSwapDirection($from, $to);
            if ($direction === null) {
                return null;
            }

            return [
                'amount' => $amount,
                'from_asset' => $from,
                'to_asset' => $to,
                'direction' => $direction,
            ];
        }

        return null;
    }
}
