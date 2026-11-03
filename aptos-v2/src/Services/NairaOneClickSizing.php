<?php

declare(strict_types=1);

namespace AptosV2\Services;

use App\Services\SendraCentiivRampService;
use App\Services\SendraOfframpBridge;
use App\Services\SendraOneClickService;

/**
 * Send-naira fixed-NGN sizing via 1Click bridge + Centiiv pool (no WhatsApp side effects).
 */
final class NairaOneClickSizing
{
    /**
     * Ledger crypto (USDT/USDC on Aptos) → 1Click dry → Stellar USDC → Centiiv NGN quote.
     *
     * @return array{recv: float, sum: array<string, mixed>, est_usdc: float, dry_data: array<string, mixed>, quote_data?: array<string, mixed>, centiiv_failed?: bool, centiiv_error?: ?string}|null
     */
    public static function bridgePricingStep(
        string $ledgerAmountStr,
        string $originNepAsset,
        string $destStellarUsdc,
        string $stellarDeposit,
        string $refundAptos,
        string $deadlineIso,
        SendraOneClickService $oneClick,
        SendraCentiivRampService $agg,
        int $oneClickQuoteWaitMs = 0,
        bool $liveQuote = false,
        string $toFiat = 'NGN'
    ): ?array {
        $toFiat = strtoupper(trim($toFiat));
        $midSmallest = SendraOneClickService::cryptoAmountToSmallestUnits($ledgerAmountStr, 6);
        $waitMs = $liveQuote
            ? SendraOneClickService::resolveConfiguredQuoteWaitMs()
            : ($oneClickQuoteWaitMs > 0
                ? $oneClickQuoteWaitMs
                : SendraOneClickService::resolveSearchQuoteWaitMs());
        $quoteBody = SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
            $originNepAsset,
            $destStellarUsdc,
            $midSmallest,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            !$liveQuote,
            $waitMs
        );
        $quoteRes = $oneClick->requestQuote($quoteBody);
        if (!$quoteRes['success'] || !is_array($quoteRes['data'] ?? null)) {
            return null;
        }
        $parsed = SendraOneClickService::parseQuoteResponse($quoteRes['data']);
        $estUsdc = (float) ($parsed['amount_out_formatted'] ?? 0);
        if ($estUsdc <= 0) {
            return null;
        }
        $pessimisticPool = !$liveQuote && $toFiat === 'NGN' && SendraCentiivRampService::isIntermediaryNgnPayoutEnabled();
        $quoteUsdc = $pessimisticPool
            ? SendraCentiivRampService::centiivQuoteUsdcForIntermediaryPool($estUsdc)
            : $estUsdc;
        $sum = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => $toFiat,
            'amount' => $quoteUsdc,
        ]);
        if (!$sum['success']) {
            return [
                'recv' => 0.0,
                'sum' => [],
                'est_usdc' => $estUsdc,
                'dry_data' => $quoteRes['data'],
                'centiiv_failed' => true,
                'centiiv_error' => $sum['error'] ?? null,
            ];
        }
        $s = SendraCentiivRampService::summarizeQuoteFiat($sum['data'], $toFiat, $quoteUsdc);
        $recv = (float) ($s['receive_fiat'] ?? $s['receive_ngn'] ?? 0);
        if ($recv <= 0) {
            return null;
        }

        return [
            'recv' => $recv,
            'sum' => $s,
            'est_usdc' => $estUsdc,
            'dry_data' => $quoteRes['data'],
            'quote_data' => $quoteRes['data'],
        ];
    }

    /**
     * Size USDT/USDC debit via 1Click bridge + Centiiv pool fiat (off-ramp path).
     * @param string $fiat Target fiat currency (NGN, XOF, KES etc.)
     */
    public static function solveForTarget(
        string $asset,
        float $avail,
        float $targetNgn,
        string $aptosRefundAddress,
        SendraCentiivRampService $agg,
        float $toleranceNgn,
        ?string $logWa = null,
        string $fiat = 'NGN'
    ): NairaSizingResult {
        $fiat = strtoupper(trim($fiat)) ?: 'NGN';
        if (!in_array($asset, ['USDT', 'USDC'], true)) {
            return NairaSizingResult::fail('unsupported_asset', '❌ Could not start this payout. Try again.');
        }
        $refundAptos = trim($aptosRefundAddress);
        if ($refundAptos === '') {
            return NairaSizingResult::fail('missing_refund_address', '❌ Could not start this payout. Try again.');
        }
        $stellarDeposit = SendraOfframpBridge::dryRecipient();
        $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        $originUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near'));
        $destStellarUsdc = SendraOfframpBridge::destAssetId();
        if ($stellarDeposit === '') {
            return NairaSizingResult::fail('missing_stellar_deposit', '❌ Could not start this payout. Try again.');
        }
        $originAsset = $asset === 'USDC' ? $originUsdc : $originUsdt;
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $oneClick = new SendraOneClickService();
        $searchWait = SendraOneClickService::resolveSearchQuoteWaitMs();
        // Intermediary pool (NGN-only): adds disburse-fee + slippage buffer on top of the target.
        // For non-NGN fiats (XOF, KES, etc.) the pool floor IS the target — no NGN padding.
        $useIntermediaryPool = $fiat === 'NGN' && SendraCentiivRampService::isIntermediaryNgnPayoutEnabled();
        $poolTargetNgn = $useIntermediaryPool
            ? SendraCentiivRampService::intermediaryPoolFloorNgn($targetNgn)
            : $targetNgn;
        $min = 0.01;
        $lo = $min;
        $hi = $avail;
        $bestAmt = null;
        $bestRecv = null;
        $bestSum = null;
        $bestDry = null;
        $bestAboveAmt = null;
        $bestAboveRecv = null;
        $bestAboveSum = null;
        $bestAboveDry = null;
        $bestEstUsdc = null;
        $maxIters = (int) ($_ENV['SENDRA_SELL_TARGET_NGN_MAX_ITERS'] ?? getenv('SENDRA_SELL_TARGET_NGN_MAX_ITERS') ?: 8);
        if ($maxIters < 4) {
            $maxIters = 4;
        }
        if ($maxIters > 14) {
            $maxIters = 14;
        }

        $hiStr = rtrim(rtrim(number_format($hi, 6, '.', ''), '0'), '.');
        $hiStep = self::bridgePricingStep(
            $hiStr !== '' ? $hiStr : (string) $hi,
            $originAsset,
            $destStellarUsdc,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            $oneClick,
            $agg,
            $searchWait,
            false,
            $fiat
        );
        if ($hiStep === null || !empty($hiStep['centiiv_failed'])) {
            return NairaSizingResult::fail('rate_unavailable', '❌ Could not get a rate right now. Try again.');
        }
        $hiRecv = (float) $hiStep['recv'];
        $minPoolRecv = $useIntermediaryPool ? $poolTargetNgn : $targetNgn;
        if ($hiRecv + 1e-6 < $minPoolRecv) {
            $sym = SendraCentiivRampService::currencySymbol($fiat);
            return NairaSizingResult::fail(
                'insufficient_balance',
                '❌ Your balance isn’t enough for *≈ ' . $sym . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                'You have *' . number_format($avail, 2) . "* {$asset}. Try a smaller amount."
            );
        }
        $loStep = null;
        if (!SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()) {
            $loStep = self::bridgePricingStep(
                (string) $min,
                $originAsset,
                $destStellarUsdc,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                $oneClick,
                $agg,
                $searchWait,
                false,
                $fiat
            );
            if ($loStep !== null && empty($loStep['centiiv_failed'])) {
                $loRecv = (float) $loStep['recv'];
                if ($hiRecv > $loRecv + 1e-6 && $poolTargetNgn > $loRecv) {
                    $fraction = ($poolTargetNgn - $loRecv) / ($hiRecv - $loRecv);
                    $fraction = max(0.0, min(1.0, $fraction));
                    $lo = max($min, $lo + $fraction * ($hi - $lo));
                }
            }
        }

        if ($useIntermediaryPool) {
            return self::solveIntermediaryFast(
                $asset,
                $targetNgn,
                $originAsset,
                $destStellarUsdc,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                $oneClick,
                $agg,
                $searchWait,
                $min,
                $hi,
                $hiStep,
                $loStep,
                $logWa,
                $fiat
            );
        }

        for ($i = 0; $i < $maxIters; $i++) {
            $mid = ($lo + $hi) / 2.0;
            $midStr = rtrim(rtrim(number_format($mid, 6, '.', ''), '0'), '.');
            if ($midStr === '' || (float) $midStr < $min) {
                $midStr = '0.01';
            }
            $step = self::bridgePricingStep(
                $midStr,
                $originAsset,
                $destStellarUsdc,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                $oneClick,
                $agg,
                $searchWait,
                false,
                $fiat
            );
            if ($step === null) {
                return NairaSizingResult::fail('rate_unavailable', '❌ Could not get a rate right now. Try again.');
            }
            if (!empty($step['centiiv_failed'])) {
                $minUsdc = SendraCentiivRampService::parseMinUsdcFromQuoteError(
                    isset($step['centiiv_error']) && is_string($step['centiiv_error']) ? $step['centiiv_error'] : null
                );
                if ($minUsdc !== null) {
                    $lo = max($lo, (float) $midStr);
                    continue;
                }

                return NairaSizingResult::fail('rate_refresh_failed', '❌ Could not refresh the rate. Try again.');
            }
            $recv = (float) $step['recv'];
            $s = $step['sum'];
            $diff = $recv - $poolTargetNgn;
            if ($bestRecv === null || abs($diff) < abs($bestRecv - $poolTargetNgn)) {
                $bestAmt = $midStr;
                $bestRecv = $recv;
                $bestSum = $s;
                $bestDry = $step['dry_data'] ?? null;
                $bestEstUsdc = (float) ($step['est_usdc'] ?? 0);
            }
            if ($recv + 1e-9 >= $poolTargetNgn) {
                if ($bestAboveRecv === null || $recv < $bestAboveRecv) {
                    $bestAboveAmt = $midStr;
                    $bestAboveRecv = $recv;
                    $bestAboveSum = $s;
                    $bestAboveDry = $step['dry_data'] ?? null;
                    $bestEstUsdc = (float) ($step['est_usdc'] ?? 0);
                }
                if (abs($diff) <= $toleranceNgn) {
                    break;
                }
                if (SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()
                    && abs($diff) <= max($toleranceNgn * 3, $targetNgn * 0.015, 10.0)) {
                    break;
                }
            }
            if ($recv >= $poolTargetNgn) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        if ($bestAboveAmt !== null && $bestAboveSum !== null && $bestAboveRecv !== null) {
            $bestAmt = $bestAboveAmt;
            $bestSum = $bestAboveSum;
            $bestRecv = $bestAboveRecv;
            $bestDry = $bestAboveDry;
        }

        if ($bestAmt === null || $bestSum === null || $bestRecv === null || $bestRecv + 1e-9 < $poolTargetNgn) {
            $sym = SendraCentiivRampService::currencySymbol($fiat);
            return NairaSizingResult::fail(
                'sizing_failed',
                '❌ Could not find a *' . $asset . '* amount that pays *≈ ' . $sym . number_format($targetNgn, 0, '.', ',') . "* right now.\nTry again or a slightly different amount."
            );
        }

        $overshootError = self::checkTargetNgnOvershoot($logWa, $targetNgn, $bestRecv, $toleranceNgn);
        if ($overshootError !== null) {
            return NairaSizingResult::fail('overshoot', $overshootError);
        }

        $result = [
            'crypto_amount' => $bestAmt,
            'receive_ngn_display' => (string) ($bestSum['receive_ngn_display'] ?? ''),
            'rate_hint' => $bestSum['rate_hint'] ?? null,
            'fee_hint' => $bestSum['fee_hint'] ?? null,
        ];
        if ($bestEstUsdc !== null && $bestEstUsdc > 0) {
            $result['oneclick_est_usdc'] = $bestEstUsdc;
        }
        if (is_array($bestDry)) {
            $result['oneclick_dry_quote_data'] = $bestDry;
            $result['oneclick_quote_data'] = $bestDry;
        }

        return NairaSizingResult::ok($result);
    }

    /**
     * Intermediary sizing: 2–6 quotes max (hi/lo + rate guess + short search). Avoids 20+ slow 1Click calls.
     *
     * @param array{recv: float, sum: array<string, mixed>, est_usdc: float, dry_data?: array<string, mixed>} $hiStep
     * @param ?array{recv: float, sum: array<string, mixed>, est_usdc: float, dry_data?: array<string, mixed>} $loStep
     */
    private static function solveIntermediaryFast(
        string $asset,
        float $targetNgn,
        string $originAsset,
        string $destStellarUsdc,
        string $stellarDeposit,
        string $refundAptos,
        string $deadlineIso,
        SendraOneClickService $oneClick,
        SendraCentiivRampService $agg,
        int $quoteWaitMs,
        float $min,
        float $hi,
        array $hiStep,
        ?array $loStep,
        ?string $logWa,
        string $fiat = 'NGN'
    ): NairaSizingResult {
        $fiat = strtoupper(trim($fiat)) ?: 'NGN';
        $maxSpread = self::intermediaryMaxPoolSpreadNgn();
        $poolFloorNgn = SendraCentiivRampService::intermediaryPoolFloorNgn($targetNgn);
        $ceiling = $poolFloorNgn + $maxSpread;
        $hiRecv = (float) $hiStep['recv'];
        $loRecv = 0.0;
        if ($loStep !== null && empty($loStep['centiiv_failed'])) {
            $loRecv = (float) $loStep['recv'];
        }

        /** @var array<string, array<string, mixed>> $cache */
        $cache = [
            rtrim(rtrim(number_format($hi, 6, '.', ''), '0'), '.') ?: (string) $hi => $hiStep,
        ];
        if ($loStep !== null && empty($loStep['centiiv_failed'])) {
            $cache[(string) $min] = $loStep;
        }

        $quoteStep = function (string $amtStr) use (
            &$cache,
            $originAsset,
            $destStellarUsdc,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            $oneClick,
            $agg,
            $quoteWaitMs,
            $fiat
        ): ?array {
            if (isset($cache[$amtStr])) {
                return $cache[$amtStr];
            }
            $step = self::bridgePricingStep(
                $amtStr,
                $originAsset,
                $destStellarUsdc,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                $oneClick,
                $agg,
                $quoteWaitMs,
                false,
                $fiat
            );
            if ($step !== null && empty($step['centiiv_failed'])) {
                $cache[$amtStr] = $step;
            }

            return $step;
        };

        $pickBest = function (?array $current, array $step, string $amtStr) use ($poolFloorNgn, $ceiling): array {
            $recv = (float) $step['recv'];
            $candidate = [
                'crypto_amount' => $amtStr,
                'recv' => $recv,
                'sum' => $step['sum'],
                'est_usdc' => (float) ($step['est_usdc'] ?? 0),
                'dry_data' => $step['dry_data'] ?? [],
            ];
            if ($recv + 1e-9 < $poolFloorNgn) {
                return $current ?? $candidate;
            }
            if ($recv > $ceiling + 1e-9) {
                return $current ?? $candidate;
            }
            if ($current === null) {
                return $candidate;
            }
            $curInBand = $current['recv'] >= $poolFloorNgn && $current['recv'] <= $ceiling;
            $newInBand = $recv >= $poolFloorNgn && $recv <= $ceiling;
            if ($newInBand && !$curInBand) {
                return $candidate;
            }
            if ($newInBand && $curInBand && (float) $amtStr < (float) $current['crypto_amount']) {
                return $candidate;
            }
            if (!$curInBand && !$newInBand && abs($recv - $poolFloorNgn) < abs($current['recv'] - $poolFloorNgn)) {
                return $candidate;
            }

            return $current;
        };

        $best = null;

        $guessAmt = $hi;
        if ($hiRecv > $loRecv + 1e-6 && $poolFloorNgn > $loRecv) {
            $aimNgn = min($ceiling, $poolFloorNgn + min(15.0, $maxSpread * 0.25));
            $fraction = ($aimNgn - $loRecv) / ($hiRecv - $loRecv);
            $fraction = max(0.05, min(0.95, $fraction));
            $guessAmt = max($min, $min + $fraction * ($hi - $min));
        } elseif ($hiRecv > 1e-6) {
            $guessAmt = max($min, min($hi, ($poolFloorNgn / $hiRecv) * $hi * 1.02));
        }
        $guessStr = rtrim(rtrim(number_format($guessAmt, 6, '.', ''), '0'), '.');
        if ($guessStr === '' || (float) $guessStr < $min) {
            $guessStr = '0.01';
        }
        $guessStep = $quoteStep($guessStr);
        if ($guessStep !== null) {
            $best = $pickBest($best, $guessStep, $guessStr);
            $guessRecv = (float) $guessStep['recv'];
            if ($guessRecv + 1e-9 >= $poolFloorNgn && $guessRecv <= $ceiling + 1e-9) {
                $guessBest = [
                    'crypto_amount' => $guessStr,
                    'recv' => $guessRecv,
                    'sum' => $guessStep['sum'],
                    'est_usdc' => (float) ($guessStep['est_usdc'] ?? 0),
                    'dry_data' => $guessStep['dry_data'] ?? [],
                ];
                $finalGuess = self::finalizeIntermediary(
                    $asset,
                    $targetNgn,
                    $guessBest,
                    $originAsset,
                    $destStellarUsdc,
                    $stellarDeposit,
                    $refundAptos,
                    $deadlineIso,
                    $oneClick,
                    $agg,
                    $logWa
                );
                if ($finalGuess instanceof NairaSizingResult) {
                    return $finalGuess;
                }
                if ($finalGuess === null) {
                    return NairaSizingResult::fail('sizing_failed', '❌ Could not get a rate right now. Try again.');
                }

                return self::okFromFinalized($finalGuess, $targetNgn);
            }
        }

        $lo = $min;
        $hiBound = $hi;
        if ($guessStep !== null) {
            $guessRecv = (float) $guessStep['recv'];
            if ($guessRecv + 1e-9 < $poolFloorNgn) {
                $lo = max($lo, (float) $guessStr);
            } elseif ($guessRecv > $ceiling + 1e-9) {
                $hiBound = min($hiBound, (float) $guessStr);
            }
        }

        for ($i = 0; $i < 5; $i++) {
            if ($hiBound <= $lo + 1e-6) {
                break;
            }
            $mid = ($lo + $hiBound) / 2.0;
            $midStr = rtrim(rtrim(number_format($mid, 6, '.', ''), '0'), '.');
            if ($midStr === '' || (float) $midStr < $min) {
                $midStr = '0.01';
            }
            $step = $quoteStep($midStr);
            if ($step === null) {
                $lo = max($lo, (float) $midStr);
                continue;
            }
            $recv = (float) $step['recv'];
            $best = $pickBest($best, $step, $midStr);
            if ($recv + 1e-9 >= $poolFloorNgn && $recv <= $ceiling + 1e-9) {
                break;
            }
            if ($recv + 1e-9 < $poolFloorNgn) {
                $lo = $mid;
            } else {
                $hiBound = $mid;
            }
        }

        if ($best === null) {
            return NairaSizingResult::fail('rate_unavailable', '❌ Could not get a rate right now. Try again.');
        }

        $needsProbe = $best['recv'] + 1e-9 < $poolFloorNgn || $best['recv'] > $ceiling + 1e-9;
        if ($needsProbe && $hiRecv > 1e-6) {
            $rateEst = max($min, min($hi, ($poolFloorNgn / $hiRecv) * $hi));
            $probes = [];
            foreach ([1.0, 0.99, 1.01, 0.985, 1.015, 0.97, 1.03] as $mult) {
                $amt = max($min, min($hi, $rateEst * $mult));
                $probes[] = rtrim(rtrim(number_format($amt, 6, '.', ''), '0'), '.') ?: (string) $min;
            }
            foreach (array_unique($probes) as $probeStr) {
                $step = $quoteStep($probeStr);
                if ($step === null) {
                    continue;
                }
                $recv = (float) $step['recv'];
                if ($recv + 1e-9 >= $poolFloorNgn && $recv <= $ceiling + 1e-9) {
                    $best = [
                        'crypto_amount' => $probeStr,
                        'recv' => $recv,
                        'sum' => $step['sum'],
                        'est_usdc' => (float) ($step['est_usdc'] ?? 0),
                        'dry_data' => $step['dry_data'] ?? [],
                    ];
                    break;
                }
                $best = $pickBest($best, $step, $probeStr);
            }
        }

        $finalBest = self::finalizeIntermediary(
            $asset,
            $targetNgn,
            $best,
            $originAsset,
            $destStellarUsdc,
            $stellarDeposit,
            $refundAptos,
            $deadlineIso,
            $oneClick,
            $agg,
            $logWa
        );
        if ($finalBest instanceof NairaSizingResult) {
            return $finalBest;
        }
        if ($finalBest === null) {
            return NairaSizingResult::fail('sizing_failed', '❌ Could not get a rate right now. Try again.');
        }

        return self::okFromFinalized($finalBest, $targetNgn);
    }

    /**
     * Find the smallest ledger debit whose pool quote covers target NGN within max spread.
     *
     * @return ?array{crypto_amount: string, recv: float, sum: array<string, mixed>, est_usdc: float, dry_data: array<string, mixed>}
     */
    private static function tightenLedgerAmount(
        float $targetNgn,
        float $maxSpread,
        float $loAmt,
        float $hiAmt,
        string $originAsset,
        string $destStellarUsdc,
        string $stellarDeposit,
        string $refundAptos,
        string $deadlineIso,
        SendraOneClickService $oneClick,
        SendraCentiivRampService $agg,
        bool $liveQuote = false
    ): ?array {
        if ($hiAmt <= $loAmt + 1e-9) {
            return null;
        }
        $ceiling = $targetNgn + $maxSpread;
        $lockWait = $liveQuote
            ? 0
            : SendraOneClickService::resolveConfiguredQuoteWaitMs();
        $lo = $loAmt;
        $hi = $hiAmt;
        $bestAmt = null;
        $bestRecv = null;
        $bestSum = null;
        $bestDry = null;
        $bestEstUsdc = null;
        $maxIters = $liveQuote ? 8 : 12;

        for ($i = 0; $i < $maxIters; $i++) {
            $mid = ($lo + $hi) / 2.0;
            $midStr = rtrim(rtrim(number_format($mid, 6, '.', ''), '0'), '.');
            if ($midStr === '' || (float) $midStr < 0.01) {
                $midStr = '0.01';
            }
            $step = self::bridgePricingStep(
                $midStr,
                $originAsset,
                $destStellarUsdc,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                $oneClick,
                $agg,
                $lockWait,
                $liveQuote
            );
            if ($step === null || !empty($step['centiiv_failed'])) {
                $lo = max($lo, (float) $midStr);
                continue;
            }
            $recv = (float) $step['recv'];
            if ($recv + 1e-9 >= $targetNgn && $recv <= $ceiling + 1e-9) {
                if ($bestRecv === null || (float) $midStr < (float) $bestAmt) {
                    $bestAmt = $midStr;
                    $bestRecv = $recv;
                    $bestSum = $step['sum'];
                    $bestDry = $step['dry_data'] ?? null;
                    $bestEstUsdc = (float) ($step['est_usdc'] ?? 0);
                }
                $hi = $mid;
            } elseif ($recv + 1e-9 < $targetNgn) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        if ($bestAmt === null || $bestRecv === null || $bestSum === null) {
            return null;
        }

        return [
            'crypto_amount' => $bestAmt,
            'recv' => $bestRecv,
            'sum' => $bestSum,
            'est_usdc' => $bestEstUsdc ?? 0.0,
            'dry_data' => is_array($bestDry) ? $bestDry : [],
        ];
    }

    /**
     * Option D: re-quote the chosen ledger amount with a live 1Click quote before PIN / debit.
     *
     * @param array{crypto_amount: string, recv: float, sum: array<string, mixed>, est_usdc: float, dry_data?: array<string, mixed>} $best
     * @return array{crypto_amount: string, recv: float, sum: array<string, mixed>, est_usdc: float, dry_data: array<string, mixed>, quote_data?: array<string, mixed>, live_quote_data?: array<string, mixed>}|NairaSizingResult|null
     */
    private static function finalizeIntermediary(
        string $asset,
        float $targetNgn,
        array $best,
        string $originAsset,
        string $destStellarUsdc,
        string $stellarDeposit,
        string $refundAptos,
        string $deadlineIso,
        SendraOneClickService $oneClick,
        SendraCentiivRampService $agg,
        ?string $logWa
    ): array|NairaSizingResult|null {
        $overshootError = self::checkTargetNgnOvershoot($logWa, $targetNgn, $best['recv'], 1.0);
        if ($overshootError !== null) {
            return NairaSizingResult::fail('overshoot', $overshootError);
        }
        $poolFloorNgn = SendraCentiivRampService::intermediaryPoolFloorNgn($targetNgn);
        if ($best['recv'] + 1e-9 < $poolFloorNgn) {
            return NairaSizingResult::fail(
                'sizing_failed',
                '❌ Could not find a *' . $asset . '* amount that pays *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "* right now.\nTry again or a slightly different amount."
            );
        }

        $final = $best;
        if (self::oneClickOfframpSizingUsesLiveQuote()) {
            $liveStep = self::bridgePricingStep(
                $best['crypto_amount'],
                $originAsset,
                $destStellarUsdc,
                $stellarDeposit,
                $refundAptos,
                $deadlineIso,
                $oneClick,
                $agg,
                0,
                true
            );
            if ($liveStep === null || !empty($liveStep['centiiv_failed'])) {
                error_log('Sendra sell: live 1Click sizing confirm failed wa=' . ($logWa ?? '') . ' amount=' . $best['crypto_amount']);

                return NairaSizingResult::fail(
                    'live_quote_failed',
                    "❌ Could not confirm the live rate right now.\nPlease try again in a moment."
                );
            }
            $liveRecv = (float) $liveStep['recv'];
            if ($liveRecv + 1e-9 < $poolFloorNgn) {
                return NairaSizingResult::fail(
                    'live_quote_insufficient',
                    '❌ The live rate would not cover *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "*.\nPlease try again."
                );
            }
            $maxOver = self::intermediaryMaxPoolOvershootNgn($targetNgn);
            $overshootCeiling = self::intermediaryPoolOvershootCeilingNgn($targetNgn);
            $pickedAmt = $best['crypto_amount'];
            $pickedRecv = $liveRecv;
            $pickedStep = $liveStep;
            if ($liveRecv > $overshootCeiling + 1e-9) {
                $tightened = self::tightenLedgerAmount(
                    $poolFloorNgn,
                    $maxOver,
                    0.01,
                    (float) $best['crypto_amount'],
                    $originAsset,
                    $destStellarUsdc,
                    $stellarDeposit,
                    $refundAptos,
                    $deadlineIso,
                    $oneClick,
                    $agg,
                    true
                );
                if ($tightened !== null) {
                    $pickedAmt = $tightened['crypto_amount'];
                    $pickedRecv = (float) $tightened['recv'];
                    $pickedStep = [
                        'sum' => $tightened['sum'],
                        'est_usdc' => $tightened['est_usdc'],
                        'quote_data' => $tightened['dry_data'] ?? [],
                    ];
                    error_log(
                        'Sendra sell: live 1Click sizing tightened wa=' . ($logWa ?? '')
                        . ' from=' . $best['crypto_amount']
                        . ' to=' . $pickedAmt
                        . ' pool_ngn=' . $pickedRecv
                    );
                }
            }
            $overshootError = self::checkTargetNgnOvershoot($logWa, $targetNgn, $pickedRecv, 1.0);
            if ($overshootError !== null) {
                return NairaSizingResult::fail('overshoot', $overshootError);
            }
            $final = [
                'crypto_amount' => $pickedAmt,
                'recv' => $pickedRecv,
                'sum' => $pickedStep['sum'],
                'est_usdc' => (float) ($pickedStep['est_usdc'] ?? 0),
                'dry_data' => is_array($best['dry_data'] ?? null) ? $best['dry_data'] : [],
                'quote_data' => $pickedStep['quote_data'] ?? $liveStep['quote_data'] ?? $liveStep['dry_data'] ?? [],
                'live_quote_data' => $pickedStep['quote_data'] ?? $liveStep['quote_data'] ?? null,
            ];
            error_log(
                'Sendra sell: live 1Click sizing ok wa=' . ($logWa ?? '')
                . ' asset=' . $asset
                . ' amount=' . $pickedAmt
                . ' dry_pool_ngn=' . $best['recv']
                . ' live_pool_ngn=' . $pickedRecv
            );
        }

        return $final;
    }

    /**
     * @param array{crypto_amount: string, recv: float, sum: array<string, mixed>, est_usdc: float, dry_data?: array<string, mixed>, quote_data?: array<string, mixed>, live_quote_data?: array<string, mixed>} $final
     */
    private static function okFromFinalized(array $final, float $targetNgn): NairaSizingResult
    {
        $result = [
            'crypto_amount' => $final['crypto_amount'],
            'receive_ngn_display' => (string) ($final['sum']['receive_ngn_display'] ?? ''),
            'rate_hint' => $final['sum']['rate_hint'] ?? null,
            'fee_hint' => $final['sum']['fee_hint'] ?? null,
            'oneclick_est_usdc' => $final['est_usdc'],
        ];
        if (is_array($final['dry_data'] ?? null) && $final['dry_data'] !== []) {
            $result['oneclick_dry_quote_data'] = $final['dry_data'];
            $result['oneclick_quote_data'] = $final['quote_data'] ?? $final['dry_data'];
        }
        if (!empty($final['live_quote_data']) && is_array($final['live_quote_data'])) {
            $result['oneclick_live_quote_data'] = $final['live_quote_data'];
        }
        if ($fiat === 'NGN' && SendraCentiivRampService::isIntermediaryNgnPayoutEnabled() && $targetNgn > 0) {
            $result['receive_ngn_pool'] = $final['recv'];
            $result['receive_ngn_display'] = self::formatRequestedNgnDisplay($targetNgn);
        }

        return NairaSizingResult::ok($result);
    }

    /** Max pool quote above requested NGN in intermediary mode (fees / slippage margin). */
    private static function intermediaryMaxPoolSpreadNgn(): float
    {
        $raw = trim((string) ($_ENV['SENDRA_INTERMEDIARY_MAX_NGN_SPREAD'] ?? getenv('SENDRA_INTERMEDIARY_MAX_NGN_SPREAD') ?: '100'));
        $spread = is_numeric($raw) ? (float) $raw : 100.0;

        return max(1.0, min(100.0, $spread));
    }

    /**
     * Max NGN the settlement pool may exceed the user's requested payout (Option C overshoot ceiling).
     * Uses max(fixed floor, percent of request).
     */
    private static function intermediaryMaxPoolOvershootNgn(float $requestedNgn): float
    {
        $fixedRaw = trim((string) ($_ENV['SENDRA_INTERMEDIARY_MAX_POOL_OVERSHOOT_NGN'] ?? getenv('SENDRA_INTERMEDIARY_MAX_POOL_OVERSHOOT_NGN') ?: '50'));
        $fixed = is_numeric($fixedRaw) ? (float) $fixedRaw : 50.0;
        $fixed = max(5.0, min(500.0, $fixed));

        $pctRaw = trim((string) ($_ENV['SENDRA_INTERMEDIARY_MAX_POOL_OVERSHOOT_PCT'] ?? getenv('SENDRA_INTERMEDIARY_MAX_POOL_OVERSHOOT_PCT') ?: '0.5'));
        $pct = is_numeric($pctRaw) ? (float) $pctRaw : 0.5;
        $pct = max(0.0, min(5.0, $pct));

        $fromPct = $requestedNgn > 0 ? $requestedNgn * ($pct / 100.0) : 0.0;

        return max($fixed, $fromPct);
    }

    /** Max pool NGN quote allowed when sizing (pool floor + overshoot band). */
    private static function intermediaryPoolOvershootCeilingNgn(float $requestedNgn): float
    {
        $poolFloor = SendraCentiivRampService::intermediaryPoolFloorNgn($requestedNgn);

        return $poolFloor + self::intermediaryMaxPoolOvershootNgn($requestedNgn);
    }

    /** Final 1Click sizing uses a live quote (slower, tracks execution better than dry-only). */
    private static function oneClickOfframpSizingUsesLiveQuote(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['SENDRA_ONECLICK_SIZING_LIVE_QUOTE'] ?? getenv('SENDRA_ONECLICK_SIZING_LIVE_QUOTE') ?: '1')));

        return !in_array($flag, ['0', 'false', 'off', 'no'], true);
    }

    private static function formatRequestedNgnDisplay(float $requestedNgn): string
    {
        return number_format(max(0, $requestedNgn), 0, '.', ',');
    }

    private static function targetNgnMaxOvershoot(float $targetNgn, float $toleranceNgn): float
    {
        return max($toleranceNgn * 5, $targetNgn * 0.10, 25.0);
    }

    /**
     * Reject when binary search did not converge (payout far above requested NGN).
     * Returns a user-facing error message, or null when OK.
     */
    private static function checkTargetNgnOvershoot(
        ?string $logWa,
        float $targetNgn,
        float $quotedRecv,
        float $toleranceNgn,
        bool $fixedNgnTarget = true,
        string $fiat = 'NGN'
    ): ?string {
        if ($fiat === 'NGN' && SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()) {
            if (!$fixedNgnTarget) {
                return null;
            }
            $overshootCeiling = self::intermediaryPoolOvershootCeilingNgn($targetNgn);
            if ($quotedRecv <= $overshootCeiling + 1e-9) {
                return null;
            }
            error_log(
                'Sendra sell: intermediary pool overshoot wa=' . ($logWa ?? '')
                . ' target=' . $targetNgn
                . ' quoted=' . $quotedRecv
                . ' ceiling=' . $overshootCeiling
            );

            return '❌ Could not size *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "* precisely right now.\nPlease try again in a moment.";
        }

        $maxOvershoot = self::targetNgnMaxOvershoot($targetNgn, $toleranceNgn);
        if ($quotedRecv <= $targetNgn + $maxOvershoot) {
            return null;
        }
        error_log(
            'Sendra sell: target NGN overshoot wa=' . ($logWa ?? '')
            . ' target=' . $targetNgn
            . ' quoted=' . $quotedRecv
            . ' max_overshoot=' . $maxOvershoot
        );

        return '❌ Could not size *≈ ₦' . number_format($targetNgn, 0, '.', ',') . "* precisely right now.\nPlease try again in a moment.";
    }
}
