<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * Stellar USDT is Tether USDT0 on-chain. WhatsApp still says USDT.
 */
final class StellarStable
{
    public static function isUsdt(string $asset): bool
    {
        $a = strtoupper(trim($asset));

        return $a === 'USDT' || $a === 'USDT0';
    }

    /** Ledger / WhatsApp ticker. */
    public static function display(string $asset): string
    {
        return self::isUsdt($asset) ? 'USDT' : 'USDC';
    }

    /** Centiiv fromAsset/toAsset on network STELLAR. */
    public static function centiivAsset(string $asset): string
    {
        return self::isUsdt($asset) ? 'USDT0' : 'USDC';
    }

    /** stellar-service currency (SAC lookup). */
    public static function serviceCurrency(string $asset): string
    {
        return self::isUsdt($asset) ? 'USDT' : 'USDC';
    }
}
