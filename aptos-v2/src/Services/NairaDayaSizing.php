<?php

declare(strict_types=1);

namespace AptosV2\Services;

use App\Services\SendraCentiivRampService;
use App\Services\SendraDayaAptosService;
use App\Services\SendraDayaFeeMath;

/**
 * Fixed-NGN send-naira sizing for Aptos → Daya (optional Sendra exact-naira pool).
 */
final class NairaDayaSizing
{
    public static function solveForTarget(
        string $asset,
        float $avail,
        float $targetNgn,
        ?string $logWa = null
    ): NairaSizingResult {
        $asset = strtoupper(trim($asset));
        $svc = new SendraDayaAptosService();
        $quotedProbe = $svc->quoteSell(1.0, $asset);
        if (empty($quotedProbe['success']) || empty($quotedProbe['rate_row'])) {
            return NairaSizingResult::fail(
                'rate_failed',
                (string) ($quotedProbe['user_message'] ?? '❌ Could not get a rate right now. Try again.')
            );
        }
        $rateRow = $quotedProbe['rate_row'];
        $fees = is_array($quotedProbe['fees'] ?? null) ? $quotedProbe['fees'] : [];

        $needed = $targetNgn;
        if (SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()) {
            $needed = SendraCentiivRampService::intermediaryPoolFloorNgn($targetNgn);
        }

        $crypto = SendraDayaFeeMath::cryptoForTargetNgn($needed, $rateRow, $fees);
        if ($crypto === null || $crypto < 0.01) {
            return NairaSizingResult::fail('rate_failed', '❌ Could not size this cash-out. Try a different amount.');
        }
        if ($avail + 1e-9 < $crypto) {
            return NairaSizingResult::fail(
                'insufficient_balance',
                '❌ Your balance isn’t enough for *₦' . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                'You have *' . number_format($avail, 2) . "* {$asset}. Try a smaller amount."
            );
        }

        $priced = SendraDayaFeeMath::quoteSellCrypto($crypto, $rateRow, $fees, $asset);
        $poolQuoted = (float) ($priced['receive_ngn'] ?? 0);
        if ($poolQuoted + 1e-9 < $needed) {
            return NairaSizingResult::fail(
                'pool_short',
                '❌ Could not guarantee *₦' . number_format($targetNgn, 0, '.', ',') . '* at the current rate. Try again or a slightly different amount.'
            );
        }

        $amtStr = SendraDayaFeeMath::formatCrypto($crypto);
        if ($logWa !== null && $logWa !== '') {
            error_log("Sendra Daya size wa={$logWa} asset={$asset} crypto={$amtStr} target={$targetNgn} pool={$poolQuoted}");
        }

        $solved = [
            'crypto_amount' => $amtStr,
            'receive_ngn_display' => NairaSizing::formatRequestedNgnDisplay($targetNgn),
            'receive_ngn_pool' => $poolQuoted,
            'sendra_quoted_receive_ngn' => $poolQuoted,
            'rate_hint' => $priced['rate_hint'] ?? null,
            'fee_hint' => $priced['fee_hint'] ?? null,
            'daya_rate_id' => (string) ($priced['rate_id'] ?? ''),
            'daya_rate' => (float) ($priced['rate'] ?? 0),
            'ramp' => 'daya',
        ];
        $solved = NairaSizing::applyIntermediarySendNairaDisplay($targetNgn, $solved, 'NGN');

        return NairaSizingResult::ok($solved);
    }
}
