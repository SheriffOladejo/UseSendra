<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Daya quote + fee schedule helpers (FX is already inside GET /v1/rates).
 */
final class SendraDayaFeeMath
{
    /**
     * @param array<string, mixed> $fees GET /v1/fees payload
     */
    public static function ngnDepositFee(float $principal, array $fees): float
    {
        $ngn = is_array($fees['ngn'] ?? null) ? $fees['ngn'] : [];
        $pct = self::num($ngn['deposit_fee_percent'] ?? 1.0);
        $cap = self::money($ngn['deposit_fee_cap'] ?? null, 100.0);
        if ($principal <= 0) {
            return 0.0;
        }

        return round(min($principal * $pct / 100.0, $cap), 2);
    }

    /**
     * Exact transfer instruction = principal + collection fee (capped).
     *
     * @param array<string, mixed> $fees
     */
    public static function ngnInstructAmount(float $principal, array $fees): float
    {
        return round(max(0, $principal) + self::ngnDepositFee($principal, $fees), 2);
    }

    /**
     * @param array<string, mixed> $fees
     */
    public static function ngnTransferFee(float $recipientAmount, array $fees): float
    {
        $ngn = is_array($fees['ngn'] ?? null) ? $fees['ngn'] : [];
        $min = self::money($ngn['minimum_transfer_amount'] ?? null, 100.0);
        if ($recipientAmount + 1e-9 < $min) {
            return 0.0;
        }
        $lowThresh = self::money($ngn['low_value_transfer_threshold'] ?? null, 1000.0);
        $lowFee = self::money($ngn['low_value_transfer_fee'] ?? null, 20.0);
        if ($recipientAmount + 1e-9 < $lowThresh) {
            return round($lowFee, 2);
        }
        $pct = self::num($ngn['transfer_fee_percent'] ?? 1.0);
        $cap = self::money($ngn['transfer_fee_cap'] ?? null, 100.0);

        return round(min($recipientAmount * $pct / 100.0, $cap), 2);
    }

    /**
     * On-chain withdraw fee in USD (treated as USDC/USDT units).
     *
     * @param array<string, mixed> $fees
     */
    public static function cryptoWithdrawFeeUsd(array $fees, string $chain = 'APTOS'): float
    {
        $cw = is_array($fees['crypto_withdrawals'] ?? null) ? $fees['crypto_withdrawals'] : [];
        $chain = strtoupper(trim($chain));
        $key = match ($chain) {
            'ETHEREUM', 'ETH' => 'ethereum_fee',
            'TRON' => 'tron_fee',
            default => 'default_fee',
        };
        $row = $cw[$key] ?? $cw['default_fee'] ?? null;

        return self::money($row, 0.0);
    }

    public static function keepAfterDeveloperFee(): float
    {
        $pct = (float) SendraDayaRampService::developerFeePct();
        $keep = 1.0 - ($pct / 100.0);

        return $keep > 0 ? $keep : 1.0;
    }

    /**
     * @param array<string, mixed> $rateRow
     * @param array<string, mixed> $fees
     * @return array{
     *   success: bool,
     *   error?: string,
     *   user_message?: string,
     *   pay_ngn: float,
     *   instruct_ngn: float,
     *   receive_crypto: float,
     *   receive_crypto_display: string,
     *   rate_id: string,
     *   rate: float,
     *   fee_bps: int,
     *   fee_hint: string,
     *   rate_hint: string,
     *   expires_at: string,
     *   min_deposit_ngn: float,
     *   quote_mode: string,
     *   developer_fee_pct: string,
     *   network_fee_usd: float
     * }
     */
    public static function quoteBuy(
        string $mode,
        ?float $payNgn,
        ?float $targetCrypto,
        array $rateRow,
        array $fees,
        string $asset,
        string $chain = 'APTOS'
    ): array {
        $rate = (float) ($rateRow['rate'] ?? 0);
        if ($rate <= 0) {
            return ['success' => false, 'error' => 'bad_rate', 'user_message' => '❌ Invalid Daya rate. Try again shortly.'] + self::emptyBuy($asset);
        }
        $minNgn = (float) ($rateRow['min_deposit_ngn'] ?? 1500);
        $keep = self::keepAfterDeveloperFee();
        $netFee = self::cryptoWithdrawFeeUsd($fees, $chain);

        // Daya TEMPORARY VA `amount` is what the user pays; NGN deposit fee is taken
        // from that deposit (not added on top), then FX + developer fee + withdraw fee.
        if ($mode === 'crypto_target') {
            $target = (float) $targetCrypto;
            if ($target < 0.01) {
                return ['success' => false, 'error' => 'amount', 'user_message' => "❌ Enter at least *0.01 {$asset}*."] + self::emptyBuy($asset);
            }
            if ($keep <= 0) {
                return ['success' => false, 'error' => 'bad_fee', 'user_message' => '❌ Fee configuration error. Try again shortly.'] + self::emptyBuy($asset);
            }
            // Daya: convert net NGN → crypto, subtract network fee, then developer %.
            $needNetNgn = (($target / $keep) + $netFee) * $rate;
            $payNgn = self::principalForNetNgn($needNetNgn, $fees);
            $receive = $target;
        } else {
            $payNgn = (float) $payNgn;
            if ($payNgn <= 0) {
                return ['success' => false, 'error' => 'amount', 'user_message' => '❌ Enter a Naira amount (e.g. *5000*).'] + self::emptyBuy($asset);
            }
            $receive = self::receiveAfterBuyFees($payNgn, $rate, $keep, $netFee, $fees);
        }

        $payNgn = (float) SendraDayaRampService::wholeNairaPrincipal($payNgn, $mode === 'crypto_target');
        $receive = self::receiveAfterBuyFees($payNgn, $rate, $keep, $netFee, $fees);
        $depositFee = self::ngnDepositFee($payNgn, $fees);
        // Instruct = VA amount the user transfers (principal). Collection fee comes out of it.
        $instruct = $payNgn;
        if ($payNgn < $minNgn) {
            $minDisp = number_format($minNgn, 0, '.', ',');

            return [
                'success' => false,
                'error' => 'min_ngn',
                'user_message' => "❌ Minimum for this rate is about *₦{$minDisp}*. Try a larger amount.",
            ] + self::emptyBuy($asset);
        }

        $feeBps = (int) ($rateRow['fee_bps'] ?? 0);
        $dev = SendraDayaRampService::developerFeePct();

        return [
            'success' => true,
            'quote_mode' => $mode,
            'pay_ngn' => $payNgn,
            'instruct_ngn' => $instruct,
            'receive_crypto' => $receive,
            'receive_crypto_display' => self::formatCrypto($receive),
            'rate_id' => (string) ($rateRow['rate_id'] ?? ''),
            'rate' => $rate,
            'fee_bps' => $feeBps,
            'fee_hint' => self::buyFeeHint($feeBps, $dev, $netFee, $asset, $depositFee),
            'rate_hint' => 'Rate: *₦' . number_format($rate, 2, '.', ',') . '* per 1 ' . $asset,
            'expires_at' => (string) ($rateRow['expires_at'] ?? ''),
            'min_deposit_ngn' => $minNgn,
            'developer_fee_pct' => $dev,
            'network_fee_usd' => $netFee,
        ];
    }

    /**
     * @param array<string, mixed> $rateRow
     * @param array<string, mixed> $fees
     * @return array{success: bool, error?: string, receive_ngn: float, receive_ngn_display: string, rate: float, rate_id: string, fee_hint: string, rate_hint: string, expires_at: string, developer_fee_pct: string, payout_fee_ngn: float}
     */
    public static function quoteSellCrypto(float $cryptoAmount, array $rateRow, array $fees, string $asset): array
    {
        $rate = (float) ($rateRow['rate'] ?? 0);
        if ($rate <= 0 || $cryptoAmount < 0.01) {
            return ['success' => false, 'error' => 'bad_rate', 'receive_ngn' => 0, 'receive_ngn_display' => '—', 'rate' => 0, 'rate_id' => '', 'fee_hint' => '', 'rate_hint' => '', 'expires_at' => '', 'developer_fee_pct' => '0', 'payout_fee_ngn' => 0];
        }
        $gross = $cryptoAmount * $rate;
        $afterDev = $gross * self::keepAfterDeveloperFee();
        $payoutFee = self::ngnTransferFee($afterDev, $fees);
        $receive = max(0.0, $afterDev - $payoutFee);
        $dev = SendraDayaRampService::developerFeePct();
        $feeBps = (int) ($rateRow['fee_bps'] ?? 0);

        return [
            'success' => true,
            'receive_ngn' => $receive,
            'receive_ngn_display' => number_format($receive, 0, '.', ','),
            'rate' => $rate,
            'rate_id' => (string) ($rateRow['rate_id'] ?? ''),
            'fee_hint' => self::sellFeeHint($feeBps, $dev, $payoutFee),
            'rate_hint' => 'Rate: *₦' . number_format($rate, 2, '.', ',') . '* per 1 ' . $asset,
            'expires_at' => (string) ($rateRow['expires_at'] ?? ''),
            'developer_fee_pct' => $dev,
            'payout_fee_ngn' => $payoutFee,
        ];
    }

    /**
     * Crypto needed so customer_amount (after Sendra 0.5% + Daya payout fee) covers $neededNgn.
     *
     * @param array<string, mixed> $rateRow
     * @param array<string, mixed> $fees
     */
    public static function cryptoForTargetNgn(float $neededNgn, array $rateRow, array $fees): ?float
    {
        $rate = (float) ($rateRow['rate'] ?? 0);
        $keep = self::keepAfterDeveloperFee();
        if ($rate <= 0 || $keep <= 0 || $neededNgn <= 0) {
            return null;
        }
        $guess = $neededNgn / ($rate * $keep);
        for ($i = 0; $i < 6; $i++) {
            $gross = $guess * $rate;
            $afterDev = $gross * $keep;
            $fee = self::ngnTransferFee($afterDev, $fees);
            $got = $afterDev - $fee;
            if ($got + 0.01 >= $neededNgn) {
                break;
            }
            $short = $neededNgn - $got;
            $guess += ($short / ($rate * $keep)) + 0.000001;
        }

        return round($guess * 1.002, 6);
    }

    public static function formatCrypto(float $n): string
    {
        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.') ?: '0';
    }

    private static function buyFeeHint(int $feeBps, string $dev, float $netFee, string $asset, float $depositFeeNgn): string
    {
        $bits = ['Fee: Daya FX is in the rate'];
        if ($feeBps > 0) {
            $bits[0] .= ' (' . rtrim(rtrim(number_format($feeBps / 100, 2, '.', ''), '0'), '.') . '%)';
        }
        if ((float) $dev > 0) {
            $bits[] = 'Sendra ' . $dev . '%';
        }
        if ($depositFeeNgn > 0.009) {
            $bits[] = 'Daya collection ≈ ₦' . number_format($depositFeeNgn, 0, '.', ',');
        }
        if ($netFee > 0) {
            $bits[] = 'network ≈ ' . self::formatCrypto($netFee) . ' ' . $asset;
        }

        return implode('; ', $bits) . '.';
    }

    /**
     * Principal the user must pay so that after Daya's NGN deposit fee, net NGN remains.
     *
     * @param array<string, mixed> $fees
     */
    public static function principalForNetNgn(float $needNetNgn, array $fees): float
    {
        if ($needNetNgn <= 0) {
            return 0.0;
        }
        $ngn = is_array($fees['ngn'] ?? null) ? $fees['ngn'] : [];
        $pct = self::num($ngn['deposit_fee_percent'] ?? 1.0);
        $cap = self::money($ngn['deposit_fee_cap'] ?? null, 100.0);
        if ($pct <= 0 || $pct >= 100) {
            return $needNetNgn + $cap;
        }
        // Uncapped: pay * (1 - pct/100) = needNet ⇒ pay = needNet / (1 - pct/100)
        $payUncapped = $needNetNgn / (1.0 - ($pct / 100.0));
        if (self::ngnDepositFee($payUncapped, $fees) + 1e-9 >= $cap) {
            return $needNetNgn + $cap;
        }

        return $payUncapped;
    }

    /**
     * @param array<string, mixed> $fees
     */
    public static function receiveAfterBuyFees(float $payNgn, float $rate, float $keep, float $netFee, array $fees): float
    {
        if ($payNgn <= 0 || $rate <= 0 || $keep <= 0) {
            return 0.0;
        }
        $netNgn = max(0.0, $payNgn - self::ngnDepositFee($payNgn, $fees));
        $grossCrypto = $netNgn / $rate;

        return max(0.0, ($grossCrypto - $netFee) * $keep);
    }

    private static function sellFeeHint(int $feeBps, string $dev, float $payoutFee): string
    {
        $bits = ['Fee: Daya FX is in the rate'];
        if ($feeBps > 0) {
            $bits[0] .= ' (' . rtrim(rtrim(number_format($feeBps / 100, 2, '.', ''), '0'), '.') . '%)';
        }
        if ((float) $dev > 0) {
            $bits[] = 'Sendra ' . $dev . '%';
        }
        if ($payoutFee > 0) {
            $bits[] = 'payout ≈ ₦' . number_format($payoutFee, 0, '.', ',');
        }

        return implode('; ', $bits) . '.';
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyBuy(string $asset): array
    {
        return [
            'pay_ngn' => 0.0,
            'instruct_ngn' => 0.0,
            'receive_crypto' => 0.0,
            'receive_crypto_display' => '—',
            'rate_id' => '',
            'rate' => 0.0,
            'fee_bps' => 0,
            'fee_hint' => '',
            'rate_hint' => '',
            'expires_at' => '',
            'min_deposit_ngn' => 0.0,
            'quote_mode' => '',
            'developer_fee_pct' => '0',
            'network_fee_usd' => 0.0,
            'asset' => $asset,
        ];
    }

    /**
     * @param mixed $row
     */
    private static function money(mixed $row, float $fallback): float
    {
        if (is_array($row) && isset($row['amount']) && is_numeric($row['amount'])) {
            return (float) $row['amount'];
        }
        if (is_numeric($row)) {
            return (float) $row;
        }

        return $fallback;
    }

    private static function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }
}
