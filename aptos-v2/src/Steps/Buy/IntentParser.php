<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

/**
 * Parses buy flow-entry commands (`buy`, `buy 5000`, `buy 5 USDC`).
 */
final class IntentParser
{
    /**
     * @return array{type: string, pay_ngn?: float, target_crypto?: float, asset?: string}|null
     */
    public static function parseEntry(string $message, string $messageLower): ?array
    {
        if ($messageLower === 'buy_fiat_ngn' || $messageLower === 'buy') {
            return ['type' => 'bare'];
        }

        if (preg_match('/^buy\s+([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', trim($message), $m)) {
            $raw = str_replace([',', ' '], '', (string) $m[1]);
            if ($raw !== '' && is_numeric($raw)) {
                $amt = (float) $raw;
                if ($amt >= 100) {
                    return ['type' => 'naira', 'pay_ngn' => $amt];
                }
            }
        }

        if (preg_match('/^buy\s+(\d+(?:\.\d+)?)\s*(usdt0|usdc|usdt)\b/i', trim($message), $m)) {
            $cryptoAmt = (float) $m[1];
            $asset = \AptosV2\Support\StellarStable::display((string) $m[2]);
            if ($cryptoAmt >= 0.01) {
                return [
                    'type' => 'crypto',
                    'target_crypto' => $cryptoAmt,
                    'asset' => $asset,
                ];
            }
        }

        return null;
    }

    public static function isNewBuyCommand(string $messageLower): bool
    {
        if ($messageLower === 'buy' || $messageLower === 'buy_fiat_ngn') {
            return true;
        }
        if (preg_match('/^buy\s+([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', trim($messageLower))) {
            return true;
        }

        return (bool) preg_match('/^buy\s+(\d+(?:\.\d+)?)\s*(usdt0|usdc|usdt)\b/i', trim($messageLower));
    }

    public static function parseNairaAmount(string $message): ?float
    {
        $t = trim($message);
        if (preg_match('/^([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', $t, $m)) {
            $raw = str_replace([',', ' '], '', (string) $m[1]);
            if ($raw !== '' && is_numeric($raw)) {
                $amt = (float) $raw;
                if ($amt >= 100) {
                    return $amt;
                }
            }
        }

        return null;
    }

    public static function messageMeansYes(string $message, string $messageLower): bool
    {
        if (in_array($messageLower, ['buy_quote_yes', 'yes', 'y'], true)) {
            return true;
        }
        $stripped = trim((string) preg_replace('/^[\s\p{So}\p{Sk}]+/u', '', $messageLower));

        return in_array($stripped, ['yes', 'y', 'buy_quote_yes'], true);
    }

    public static function parseAssetPick(string $messageLower): ?string
    {
        if ($messageLower === 'buy_pick_usdt' || $messageLower === 'usdt' || $messageLower === 'usdt0') {
            return 'USDT';
        }
        if ($messageLower === 'buy_pick_usdc' || $messageLower === 'usdc') {
            return 'USDC';
        }

        return null;
    }
}
