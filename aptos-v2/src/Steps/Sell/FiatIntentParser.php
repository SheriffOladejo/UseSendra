<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

/**
 * Parses quick cashout commands for NGN, XOF (CFA), and KES.
 * E.g. `send 10000 CFA to 2291234567`, `send 5000 XOF to 22890123456`, `send 1000 naira to 0123456789 GTBank`.
 */
final class FiatIntentParser
{
    /**
     * @return array{amount: float, fiat: string, account: string, bank_guess: string}|null
     */
    public static function parse(string $message): ?array
    {
        if (!preg_match(
            '/^(?:send|pay)\s+([\d,\s]+(?:\.\d+)?)\s*(naira|ngn|cfa|xof|kes)\s+(?:to\s+)?(\+?\d{7,15})(?:\s+(.+))?$/iu',
            trim($message),
            $m
        )) {
            return null;
        }

        $rawAmt = str_replace([',', ' '], '', (string) $m[1]);
        if ($rawAmt === '' || !is_numeric($rawAmt)) {
            return null;
        }

        $amt = (float) $rawAmt;
        if ($amt <= 0) {
            return null;
        }

        $token = strtolower(trim((string) $m[2]));
        $fiat = match ($token) {
            'cfa', 'xof' => 'XOF',
            'kes' => 'KES',
            default => 'NGN',
        };

        $account = preg_replace('/\D/', '', (string) $m[3]);
        $bankGuess = isset($m[4]) ? trim((string) $m[4]) : '';

        if ($bankGuess === '') {
            if ($fiat === 'XOF' || str_starts_with($account, '229') || str_starts_with($account, '228')) {
                $bankGuess = 'MoMo';
            } elseif ($fiat === 'KES' || str_starts_with($account, '254')) {
                $bankGuess = 'Mpesa';
            }
        }

        return [
            'amount' => $amt,
            'fiat' => $fiat,
            'account' => $account,
            'bank_guess' => $bankGuess,
        ];
    }
}
