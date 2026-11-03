<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * User-facing swap errors — never expose Panora, Move abort codes, or vm_status text.
 */
final class SwapUserMessageSanitizer
{
    public static function executionFailure(string $raw, string $fromAsset): string
    {
        $lower = strtolower(trim($raw));
        $asset = trim($fromAsset) !== '' ? trim($fromAsset) : 'funds';

        if (self::looksLikeInsufficientBalance($lower)) {
            return "Not enough *{$asset}* to complete this swap.\n\n"
                . 'Try a smaller amount or type *balance* to check your wallet.';
        }

        if (str_contains($lower, 'slippage') || str_contains($lower, 'minimum amount')) {
            return "The rate moved too much to complete at the quoted price.\n\n"
                . 'Please start a new swap to get a fresh quote.';
        }

        if (str_contains($lower, 'expired') || str_contains($lower, 'timeout')) {
            return "This swap took too long to confirm.\n\nPlease start again.";
        }

        return "We couldn't complete your swap right now.\n\nPlease try again in a moment.";
    }

    public static function quoteFailure(string $raw = ''): string
    {
        return "We couldn't get a live swap quote right now.\n\nPlease try again in a moment.";
    }

    private static function looksLikeInsufficientBalance(string $lower): bool
    {
        return str_contains($lower, 'einsufficient_balance')
            || str_contains($lower, 'insufficient balance')
            || str_contains($lower, 'not enough');
    }
}
