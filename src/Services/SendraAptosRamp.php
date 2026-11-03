<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Which rail handles custodial Aptos buy/sell.
 * 1Click + Centiiv stay intact; set SENDRA_APTOS_RAMP=oneclick to switch back.
 */
final class SendraAptosRamp
{
    public const DAYA = 'daya';
    public const ONECLICK = 'oneclick';

    public static function mode(): string
    {
        $raw = strtolower(trim((string) ($_ENV['SENDRA_APTOS_RAMP'] ?? getenv('SENDRA_APTOS_RAMP') ?: self::DAYA)));
        if (in_array($raw, ['oneclick', '1click', 'centiiv'], true)) {
            return self::ONECLICK;
        }

        return self::DAYA;
    }

    public static function dayaConfigured(): bool
    {
        return (new SendraDayaRampService())->isConfigured();
    }

    public static function usesDayaForAptos(): bool
    {
        return self::mode() === self::DAYA && self::dayaConfigured();
    }
}
