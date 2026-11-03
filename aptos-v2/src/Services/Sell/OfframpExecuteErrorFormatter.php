<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

/**
 * User-safe aptos-service execute failure messages for off-ramp.
 * SmoothSend failures get a short public code (SS-*) for support triage; details stay in logs.
 */
final class OfframpExecuteErrorFormatter
{
    /**
     * @return array{code: string, category: string, user_code: string}|null
     */
    public static function classifySmoothSend(string $err): ?array
    {
        $lc = strtolower($err);
        if (!str_contains($lc, 'smoothsend')) {
            return null;
        }

        if (str_contains($lc, 'request timeout') || str_contains($lc, 'request timed out') || str_contains($lc, 'timed out')) {
            return ['code' => 'SS-T', 'category' => 'timeout', 'user_code' => 'SS-T'];
        }
        if (str_contains($lc, 'expired before submission') || str_contains($lc, 'transaction has expired')) {
            return ['code' => 'SS-E', 'category' => 'tx_expired', 'user_code' => 'SS-E'];
        }
        if (str_contains($lc, 'failed to process gasless')) {
            return ['code' => 'SS-R', 'category' => 'relayer_reject', 'user_code' => 'SS-R'];
        }
        if (str_contains($lc, '429') || str_contains($lc, 'rate limit')) {
            return ['code' => 'SS-L', 'category' => 'rate_limit', 'user_code' => 'SS-L'];
        }
        if (str_contains($lc, 'insufficient') && (str_contains($lc, 'credit') || str_contains($lc, '402'))) {
            return ['code' => 'SS-C', 'category' => 'credits', 'user_code' => 'SS-C'];
        }

        return ['code' => 'SS-X', 'category' => 'other', 'user_code' => 'SS-X'];
    }

    public static function isSmoothSendError(string $err): bool
    {
        return self::classifySmoothSend($err) !== null;
    }

    public static function shouldRetrySmoothSend(?array $smoothClass): bool
    {
        if ($smoothClass === null) {
            return false;
        }

        return in_array($smoothClass['code'], ['SS-T', 'SS-E'], true);
    }

    /**
     * Structured backend log for aptos execute failures (full detail; not sent to WhatsApp).
     *
     * @param array<string, mixed> $context
     */
    public static function logExecuteFailure(string $from, string $err, array $context = []): void
    {
        $smooth = self::classifySmoothSend($err);
        $payload = array_merge($context, [
            'user' => $from,
            'error' => $err,
            'smoothsend' => $smooth !== null,
        ]);
        if ($smooth !== null) {
            $payload['smoothsend_code'] = $smooth['code'];
            $payload['smoothsend_category'] = $smooth['category'];
        }

        error_log('[Sendra offramp aptos_execute] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function format(string $err): string
    {
        $smooth = self::classifySmoothSend($err);
        if ($smooth !== null) {
            return "❌ Could not start your payout right now ({$smooth['user_code']}).\n\n"
                . "Your wallet balance is unchanged. Please try again in a few minutes.";
        }

        $lc = strtolower($err);
        if (str_contains($lc, '429') || str_contains($lc, 'too many requests') || str_contains($lc, 'rate limit')) {
            return "❌ Network is busy right now.\n\nYour wallet balance is unchanged. Please try again in a few minutes.";
        }
        if (str_contains($lc, 'insufficient_balance') || str_contains($lc, 'insufficient balance')) {
            return "❌ Insufficient balance for this payout.\n\nYour wallet balance is unchanged. Check your balance and try a smaller amount.";
        }
        if (str_contains($lc, 'panora') && str_contains($lc, 'api_key')) {
            return "❌ Token swap isn't configured right now.\n\nYour wallet balance is unchanged. Contact support@usesendra.com.";
        }

        return "❌ Could not start your payout right now.\n\nYour wallet balance is unchanged. Please try again in a few minutes.";
    }
}
