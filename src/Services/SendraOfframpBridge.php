<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aptos → Centiiv USDC pay-in via 1Click.
 *
 * SENDRA_ONECLICK_OFFRAMP_DEST=solana | stellar (default solana if unset).
 * Aptos ↔ Stellar was quoting again on 2026-09-12, then 1Click returned
 * "Quoting for this pair is not available" on 2026-09-14. Use solana until that pair is live.
 */
final class SendraOfframpBridge
{
    public const DEST_SOLANA = 'solana';
    public const DEST_STELLAR = 'stellar';

    public const SOLANA_USDC_ASSET_ID = 'nep141:sol-5ce3bf3a31af18be40ba30f721101b4341690186.omft.near';
    public const STELLAR_USDC_ASSET_ID = 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu';
    public const SOLANA_PLACEHOLDER = 'Dr2PgL5XTSjBsG8RjJE82dceaMKdxZFica3CrXbT3o8z';
    public const STELLAR_PLACEHOLDER = 'GDT3S7V7YA6V3ILMMEP2AGC5IXPSDDXV5KAGFXCDT2E7SEVHNUYBSEA7';

    public static function dest(): string
    {
        $raw = strtolower(trim((string) ($_ENV['SENDRA_ONECLICK_OFFRAMP_DEST'] ?? getenv('SENDRA_ONECLICK_OFFRAMP_DEST') ?: self::DEST_SOLANA)));
        if (in_array($raw, ['stellar', 'xlm', 'star'], true)) {
            return self::DEST_STELLAR;
        }

        return self::DEST_SOLANA;
    }

    public static function isSolana(): bool
    {
        return self::dest() === self::DEST_SOLANA;
    }

    public static function centiivNetwork(): string
    {
        $override = strtoupper(trim((string) ($_ENV['SENDRA_CENTIIV_OFFRAMP_NETWORK'] ?? getenv('SENDRA_CENTIIV_OFFRAMP_NETWORK') ?: '')));
        if ($override !== '') {
            return $override;
        }

        return self::isSolana() ? 'SOLANA' : 'STELLAR';
    }

    public static function destAssetId(): string
    {
        if (self::isSolana()) {
            return trim((string) ($_ENV['SENDRA_ONECLICK_SOLANA_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_SOLANA_USDC_ASSET_ID') ?: self::SOLANA_USDC_ASSET_ID));
        }

        return trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: self::STELLAR_USDC_ASSET_ID));
    }

    public static function destDecimals(): int
    {
        if (self::isSolana()) {
            $n = (int) ($_ENV['SENDRA_ONECLICK_SOLANA_USDC_DECIMALS'] ?? getenv('SENDRA_ONECLICK_SOLANA_USDC_DECIMALS') ?: 6);

            return $n > 0 ? $n : 6;
        }
        $n = (int) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_DECIMALS'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_DECIMALS') ?: 7);

        return $n > 0 ? $n : 7;
    }

    /** Dry-quote recipient (no funds move). */
    public static function dryRecipient(): string
    {
        if (self::isSolana()) {
            $pooled = trim((string) ($_ENV['SENDRA_CENTIIV_SOLANA_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_SOLANA_USDC_DEPOSIT') ?: ''));
            if ($pooled !== '' && SendraCentiivRampService::looksLikeSolanaAddress($pooled)) {
                return $pooled;
            }

            return self::placeholderRecipient();
        }

        return trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
    }

    /** Valid-shaped address for live preflight quotes before Centiiv returns a real pay-in. */
    public static function placeholderRecipient(): string
    {
        if (self::isSolana()) {
            $refund = trim((string) ($_ENV['SENDRA_SOLANA_REFUND_ADDRESS'] ?? getenv('SENDRA_SOLANA_REFUND_ADDRESS') ?: self::SOLANA_PLACEHOLDER));

            return $refund !== '' ? $refund : self::SOLANA_PLACEHOLDER;
        }

        return self::STELLAR_PLACEHOLDER;
    }

    public static function centiivRefundAddress(): string
    {
        return self::centiivRefundAddressForNetwork(self::centiivNetwork());
    }

    /**
     * Refund address must match the Centiiv order network. Native Stellar sells set
     * network=STELLAR even when 1Click offramp dest is still Solana — using the
     * Solana refund there is rejected ("Must be a G... address").
     */
    public static function centiivRefundAddressForNetwork(string $network): string
    {
        $n = strtoupper(trim($network));
        if ($n === 'STELLAR' || $n === 'XLM') {
            return self::stellarRefundG();
        }

        $refund = trim((string) ($_ENV['SENDRA_SOLANA_REFUND_ADDRESS'] ?? getenv('SENDRA_SOLANA_REFUND_ADDRESS') ?: ''));
        if ($refund !== '' && SendraCentiivRampService::looksLikeSolanaAddress($refund)) {
            return $refund;
        }

        return '';
    }

    /** Classic G only — Centiiv will not refund to a C-address. Prefer Freighter / ops G, not the pool G. */
    public static function stellarRefundG(): string
    {
        foreach (['SENDRA_CENTIIV_STELLAR_REFUND_ADDRESS', 'SENDRA_STELLAR_REFUND_ADDRESS'] as $key) {
            $explicit = trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
            if ($explicit !== '' && SendraCentiivRampService::looksLikeStellarAccountId($explicit)) {
                return $explicit;
            }
        }

        return \AptosV2\Support\StellarReceive::sharedAddress();
    }

    public static function looksLikePayinAddress(string $s): bool
    {
        return self::isSolana()
            ? SendraCentiivRampService::looksLikeSolanaAddress($s)
            : SendraCentiivRampService::looksLikeStellarAccountId($s);
    }

    /**
     * @param array<string, mixed> $centiivOrderData
     */
    public static function extractPayinAddress(array $centiivOrderData): ?string
    {
        return self::isSolana()
            ? SendraCentiivRampService::extractSolanaDepositAddress($centiivOrderData)
            : SendraCentiivRampService::extractStellarDepositAddress($centiivOrderData);
    }

    public static function isConfigured(): bool
    {
        if (self::isSolana()) {
            return self::looksLikePayinAddress(self::dryRecipient())
                || self::looksLikePayinAddress(self::placeholderRecipient());
        }
        $g = self::dryRecipient();

        return $g !== '' && strlen($g) >= 5 && $g[0] === 'G';
    }

    public static function oneclickRampConfigured(): bool
    {
        $enabled = strtolower(trim((string) ($_ENV['SENDRA_ONECLICK_RAMP_ENABLED'] ?? getenv('SENDRA_ONECLICK_RAMP_ENABLED') ?: '1')));
        if (in_array($enabled, ['0', 'false', 'off'], true)) {
            return false;
        }
        if (!self::isConfigured()) {
            return false;
        }

        return (new SendraOneClickService())->isConfigured();
    }

    public static function quotedUsdcOut(array $parsed): float
    {
        $formatted = (float) ($parsed['amount_out_formatted'] ?? 0);
        if ($formatted > 0) {
            return $formatted;
        }
        $smallest = (string) ($parsed['amount_out'] ?? '0');

        return (float) SendraOneClickService::smallestUnitsToFloatString($smallest, self::destDecimals());
    }

    /** 1Click origin for buys (Centiiv pays this chain into the 1Click deposit). */
    public static function originRefundAddress(): string
    {
        $refund = self::centiivRefundAddress();
        if ($refund !== '') {
            return $refund;
        }

        return self::isSolana() ? self::placeholderRecipient() : self::dryRecipient();
    }

    public static function originRequiresMemo(): bool
    {
        return !self::isSolana();
    }
}
