<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

/**
 * Parses `send 1000 naira to 0123456789 GTBank` style fixed-NGN cash-outs.
 */
final class NairaIntentParser
{
    /**
     * @return array{amount_ngn: float, account: string, bank_guess: string, fiat?: string}|null
     */
    public static function parse(string $message): ?array
    {
        $parsed = FiatIntentParser::parse($message);
        if ($parsed === null) {
            return null;
        }

        return [
            'amount_ngn' => $parsed['amount'],
            'account' => $parsed['account'],
            'bank_guess' => $parsed['bank_guess'],
            'fiat' => $parsed['fiat'],
        ];
    }
}
