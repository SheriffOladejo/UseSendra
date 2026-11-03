<?php

declare(strict_types=1);

namespace AptosV2\Steps\Polymarket;

/**
 * `polymarket`, `polymarket 5000`, `polymarket 10 usdt`.
 */
final class IntentParser
{
    /**
     * @return array{type: string, pay_ngn?: float, target_crypto?: float}|null
     */
    public static function parseEntry(string $message, string $messageLower): ?array
    {
        $t = trim($messageLower);
        if ($t === 'fund polymarket' || str_starts_with($t, 'fund polymarket ')) {
            $t = preg_replace('/^fund\s+/', '', $t) ?? $t;
        }

        if ($t === 'polymarket' || $t === '/polymarket') {
            return ['type' => 'bare'];
        }

        if (preg_match('/^polymarket\s+([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', $t, $m)) {
            $raw = str_replace([',', ' '], '', (string) $m[1]);
            if ($raw !== '' && is_numeric($raw)) {
                $amt = (float) $raw;
                if ($amt >= 100) {
                    return ['type' => 'naira', 'pay_ngn' => $amt];
                }
            }
        }

        if (preg_match('/^polymarket\s+(\d+(?:\.\d+)?)\s*(usdt|usdc)\b/i', $t, $m)) {
            $cryptoAmt = (float) $m[1];
            if ($cryptoAmt >= 0.01) {
                return ['type' => 'crypto', 'target_crypto' => $cryptoAmt];
            }
        }

        return null;
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

    public static function parseCryptoAmount(string $message): ?float
    {
        $t = trim($message);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(usdt|usdc)?\s*$/i', $t, $m)) {
            $amt = (float) $m[1];
            if ($amt >= 0.01 && $amt < 1000) {
                return $amt;
            }
        }

        return null;
    }

    public static function messageMeansYes(string $message, string $messageLower): bool
    {
        if (in_array($messageLower, ['polymarket_yes', 'yes', 'y'], true)) {
            return true;
        }
        $stripped = trim((string) preg_replace('/^[\s\p{So}\p{Sk}]+/u', '', $messageLower));

        return $stripped === 'yes' || $stripped === 'y';
    }

    public static function normalizePolygonAddress(string $message): ?string
    {
        $t = trim($message);
        $t = preg_replace('/^address[:\s]+/i', '', $t) ?? $t;
        $t = trim($t, " \t\n\r`\"'");
        if (preg_match('/(0x[a-fA-F0-9]{40})\b/', $t, $m) !== 1) {
            return null;
        }
        $addr = $m[1];
        if (preg_match('/^0x0{40}$/i', $addr)) {
            return null;
        }
        // Aptos Sendra wallets are 64 hex chars — reject those if the user pasted one whole.
        if (preg_match('/0x[a-fA-F0-9]{64}/', $t) && !preg_match('/0x[a-fA-F0-9]{40}(?![a-fA-F0-9])/', $t)) {
            return null;
        }

        return $addr;
    }
}
