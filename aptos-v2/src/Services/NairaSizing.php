<?php

declare(strict_types=1);

namespace AptosV2\Services;

use App\Services\SendraCentiivRampService;
use App\Services\SendraOneClickService;

/**
 * Fixed-NGN send-naira sizing — phased port from MessageController.
 *
 * Phase 1: USDC direct (Centiiv rate math) + config helpers.
 * Phase 2: USDT/USDC via 1Click ledger ({@see NairaOneClickSizing}).
 * Slow/binary-search sizing still falls back to legacy when fast quote is off.
 */
final class NairaSizing
{
    public static function fastQuoteEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['SENDRA_SELL_TARGET_NGN_FAST_QUOTE'] ?? getenv('SENDRA_SELL_TARGET_NGN_FAST_QUOTE') ?: '1')));

        return !in_array($flag, ['0', 'false', 'off', 'no'], true);
    }

    public static function toleranceNgn(): float
    {
        $tolerance = (float) ($_ENV['SENDRA_SELL_TARGET_NGN_TOLERANCE'] ?? getenv('SENDRA_SELL_TARGET_NGN_TOLERANCE') ?: 1);
        if ($tolerance < 1) {
            $tolerance = 1;
        }

        return $tolerance;
    }

    public static function poolSizingTarget(float $requestedNgn): float
    {
        return SendraCentiivRampService::intermediaryPoolFloorNgn($requestedNgn);
    }

    public static function oneclickCentiivRampConfigured(): bool
    {
        return \App\Services\SendraOfframpBridge::oneclickRampConfigured();
    }

    public static function formatRequestedNgnDisplay(float $requestedNgn): string
    {
        return number_format(max(0, $requestedNgn), 0, '.', ',');
    }

    public static function buildCentiivMinPayoutMessage(SendraCentiivRampService $agg, float $minUsdc): string
    {
        return $agg->buildMinPayoutUserMessage($minUsdc);
    }

    /**
     * Apply intermediary display fields after sizing (user sees requested NGN, not pool quote).
     *
     * @param array<string, mixed> $solved
     * @return array<string, mixed>
     */
    public static function applyIntermediarySendNairaDisplay(float $targetNgn, array $solved, string $fiat = 'NGN'): array
    {
        if (strtoupper(trim($fiat)) !== 'NGN' || !SendraCentiivRampService::isIntermediaryNgnPayoutEnabled() || $targetNgn <= 0) {
            return $solved;
        }

        $poolQuoted = (float) ($solved['receive_ngn_pool'] ?? 0);
        $poolFloor = self::poolSizingTarget($targetNgn);
        if ($poolQuoted > 0 && $poolQuoted + 1e-9 < $poolFloor) {
            return $solved;
        }

        $solved['receive_ngn_display'] = self::formatRequestedNgnDisplay($targetNgn);
        if ($poolQuoted > 0) {
            $solved['sendra_quoted_receive_ngn'] = $poolQuoted;
        }

        return $solved;
    }

    /**
     * Main entry — routes to ported paths or signals legacy fallback.
     */
    public static function solve(
        string $asset,
        float $avail,
        float $targetNgn,
        string $aptosRefundAddress,
        SendraCentiivRampService $agg,
        ?string $logWa = null,
        string $fiat = 'NGN'
    ): NairaSizingResult {
        $asset = strtoupper(trim($asset));
        $fiat = strtoupper(trim($fiat)) ?: 'NGN';
        if ($targetNgn < 0.01) {
            $sym = SendraCentiivRampService::currencySymbol($fiat);
            $minLabel = $fiat === 'NGN' ? '*₦100*' : "*{$sym}1*";
            return NairaSizingResult::fail('min_target', "Cash-out needs at least {$minLabel}.");
        }
        if ($avail < 0.01) {
            return NairaSizingResult::fail(
                'no_balance',
                "❌ No *{$asset}* balance here. Pick the other token or type *cancel*."
            );
        }
        if (!in_array($asset, ['USDC', 'USDT'], true)) {
            return NairaSizingResult::fail('unsupported_asset', '⚠️ Only *USDC* or *USDT* are supported.');
        }

        if (!self::fastQuoteEnabled()) {
            return NairaSizingResult::fail(
                'fast_quote_required',
                '❌ Could not get a live size for this cash-out. Try again shortly, or *sell 5 USDC*.'
            );
        }

        $tolerance = self::toleranceNgn();

        if ($asset === 'USDT' && !self::oneclickCentiivRampConfigured() && !\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            return NairaSizingResult::fail(
                'usdt_unavailable',
                "❌ USDT isn't available for this flow here. Tap *USDC* or type *cancel*."
            );
        }

        if ($fiat === 'NGN' && \App\Services\SendraAptosRamp::usesDayaForAptos()) {
            return NairaDayaSizing::solveForTarget($asset, $avail, $targetNgn, $logWa);
        }

        if (self::oneclickCentiivRampConfigured()) {
            return NairaOneClickSizing::solveForTarget(
                $asset,
                $avail,
                $targetNgn,
                $aptosRefundAddress,
                $agg,
                $tolerance,
                $logWa,
                $fiat
            );
        }

        if ($asset === 'USDC') {
            return self::solveUsdcDirect($avail, $targetNgn, $agg, $tolerance, $fiat);
        }

        return NairaSizingResult::fail(
            'usdt_unavailable',
            "❌ USDT isn't available for this flow here. Tap *USDC* or type *cancel*."
        );
    }

    public static function solveUsdcDirect(
        float $availUsdc,
        float $targetNgn,
        SendraCentiivRampService $agg,
        float $toleranceNgn,
        string $fiat = 'NGN'
    ): NairaSizingResult {
        $fiat = strtoupper(trim($fiat)) ?: 'NGN';
        $sym = SendraCentiivRampService::currencySymbol($fiat);
        $solveTargetNgn = self::poolSizingTarget($targetNgn);
        $solved = $agg->solveUsdcForTargetNgn($solveTargetNgn, $toleranceNgn);
        if ($solved === null) {
            return NairaSizingResult::fail('rate_failed', '❌ Could not get a rate right now. Try again.');
        }
        if (isset($solved['centiiv_min_usdc'])) {
            return NairaSizingResult::fail(
                'min_payout',
                self::buildCentiivMinPayoutMessage($agg, (float) $solved['centiiv_min_usdc'])
            );
        }

        $amtStr = (string) $solved['usdc_amount_str'];
        $amt = (float) $amtStr;
        if ($availUsdc + 1e-9 < $amt) {
            return NairaSizingResult::fail(
                'insufficient_balance',
                '❌ Your balance isn’t enough for *≈ ' . $sym . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                'You have *' . number_format($availUsdc, 2) . "* USDC. Try a smaller amount."
            );
        }

        $sum = $solved['sum'];

        return NairaSizingResult::ok([
            'crypto_amount' => $amtStr,
            'receive_ngn_display' => (string) ($sum['receive_ngn_display'] ?? ''),
            'rate_hint' => $sum['rate_hint'] ?? null,
            'fee_hint' => $sum['fee_hint'] ?? null,
        ]);
    }

    public static function solveStellarDirect(
        string $asset,
        float $avail,
        float $targetNgn,
        SendraCentiivRampService $agg,
        float $toleranceNgn,
        string $fiat = 'NGN',
        string $network = 'STELLAR',
        string $walletLabel = 'Stellar'
    ): NairaSizingResult {
        $asset = \AptosV2\Support\StellarStable::display($asset);
        $label = $asset;
        $fiat = strtoupper(trim($fiat)) ?: 'NGN';
        $sym = SendraCentiivRampService::currencySymbol($fiat);
        $solveTargetNgn = self::poolSizingTarget($targetNgn);
        $solved = $agg->solveUsdcForTargetFiat($solveTargetNgn, $fiat, $toleranceNgn, [
            'fromAsset' => $network === 'ARC' ? 'USDC' : \AptosV2\Support\StellarStable::centiivAsset($asset),
            'network' => $network,
        ]);
        if ($solved === null) {
            return NairaSizingResult::fail('rate_failed', '❌ Could not get a rate right now. Try again.');
        }
        if (isset($solved['centiiv_min_usdc'])) {
            return NairaSizingResult::fail(
                'min_payout',
                self::buildCentiivMinPayoutMessage($agg, (float) $solved['centiiv_min_usdc'])
            );
        }

        $amtStr = (string) $solved['usdc_amount_str'];
        $amt = (float) $amtStr;
        if ($avail + 1e-9 < $amt) {
            return NairaSizingResult::fail(
                'insufficient_balance',
                '❌ Your ' . $walletLabel . ' balance isn’t enough for *≈ ' . $sym . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                'You have *' . number_format($avail, 2) . "* {$label}. Try a smaller amount."
            );
        }

        $sum = $solved['sum'];

        return NairaSizingResult::ok([
            'crypto_amount' => $amtStr,
            'receive_ngn_display' => (string) ($sum['receive_ngn_display'] ?? ''),
            'rate_hint' => $sum['rate_hint'] ?? null,
            'fee_hint' => $sum['fee_hint'] ?? null,
        ]);
    }
}
