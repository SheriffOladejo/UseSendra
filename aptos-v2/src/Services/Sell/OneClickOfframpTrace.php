<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

/**
 * Structured logging for 1Click off-ramp execution (v2).
 */
final class OneClickOfframpTrace
{
    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $step, string $from, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }
        $context['step'] = $step;
        $context['user'] = $from;
        $context['ts'] = date('c');
        error_log('[Sendra 1Click offramp v2] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function enabled(): bool
    {
        $v = strtolower(trim((string) ($_ENV['SENDRA_1CLICK_TRACE'] ?? getenv('SENDRA_1CLICK_TRACE') ?: '')));
        if ($v === '') {
            return true;
        }

        return !in_array($v, ['0', 'false', 'no', 'off'], true);
    }
}
