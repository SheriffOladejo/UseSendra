<?php

declare(strict_types=1);

namespace AptosV2\Steps\Send;

/**
 * Parses `send [amount] [currency] to [recipient]` crypto P2P commands.
 */
final class IntentParser
{
    public static function isHelpOnly(string $messageLower): bool
    {
        return $messageLower === 'send' || $messageLower === '/send';
    }

    /**
     * True for crypto wallet sends — excludes Naira bank cash-out (`send … naira …`).
     */
    public static function isCryptoSendIntent(string $messageLower): bool
    {
        if ($messageLower === 'send' || $messageLower === '/send') {
            return true;
        }

        if (!str_starts_with($messageLower, 'send ')) {
            return false;
        }

        return !preg_match('/\b(naira|ngn)\b/i', $messageLower);
    }

    /**
     * @return array{amount: string, currency: string, recipient: string}|null
     */
    public static function parse(string $message): ?array
    {
        if (!preg_match('/^send\s+([\d.]+)\s+([a-zA-Z0-9]+)\s+to\s+(.+)$/i', trim($message), $matches)) {
            return null;
        }

        $amount = $matches[1];
        $currency = strtoupper($matches[2]);
        if ($currency === 'USDT0') {
            $currency = 'USDT';
        }
        $recipient = trim($matches[3]);

        if (!is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        if (!in_array($currency, ['USDT', 'USDC'], true)) {
            return null;
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
            'recipient' => $recipient,
        ];
    }
}
