<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

/**
 * Parses crypto-first sell one-liners and amount-step replies.
 */
final class CryptoIntentParser
{
    /**
     * @return array{amount: string, asset: string}|null
     */
    public static function parseOneLiner(string $message): ?array
    {
        if (!preg_match('/^sell\s+(\d+(?:\.\d+)?)\s*(usdt0|usdc|usdt)\b/i', trim($message), $m)) {
            return null;
        }

        return [
            'amount' => (string) $m[1],
            'asset' => \AptosV2\Support\StellarStable::display((string) $m[2]),
        ];
    }

    /**
     * @return array{amount: string, asset: string}|null
     */
    public static function parseAmountLine(string $message): ?array
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(usdt0|usdc|usdt)\s*$/i', trim($message), $m)) {
            return null;
        }

        return [
            'amount' => (string) $m[1],
            'asset' => \AptosV2\Support\StellarStable::display((string) $m[2]),
        ];
    }
}
