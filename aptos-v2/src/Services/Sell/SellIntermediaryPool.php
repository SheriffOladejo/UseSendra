<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

use App\Services\SendraCentiivRampService;

/**
 * Settlement-pool NGN checks for send-naira / fixed-NGN off-ramps.
 */
final class SellIntermediaryPool
{
    public function poolShortfallToleranceNgn(): float
    {
        return SendraCentiivRampService::intermediaryPoolShortfallToleranceNgn();
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $sellCtx
     */
    public function isFixedNgnTarget(array $meta = [], array $sellCtx = []): bool
    {
        $fiat = strtoupper((string) ($meta['fiat'] ?? $sellCtx['fiat'] ?? 'NGN'));
        if ($fiat !== 'NGN') {
            return false;
        }

        $fixed = (float) ($meta['target_receive_ngn'] ?? $sellCtx['target_receive_ngn'] ?? 0);
        if ($fixed >= 100) {
            return true;
        }

        return !empty($meta['send_naira_quick']) || !empty($sellCtx['send_naira_quick']);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $sellCtx
     */
    public function resolveRequestedNgn(array $meta = [], array $sellCtx = []): float
    {
        $fiat = strtoupper((string) ($meta['fiat'] ?? $sellCtx['fiat'] ?? 'NGN'));
        if ($fiat !== 'NGN') {
            return 0.0;
        }

        return SendraCentiivRampService::resolveIntermediaryRequestedNgnFromPendingMeta($meta, $sellCtx);
    }

    public function maxPoolOvershootNgn(float $requestedNgn): float
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

    public function poolOvershootCeilingNgn(float $requestedNgn): float
    {
        return SendraCentiivRampService::intermediaryPoolFloorNgn($requestedNgn)
            + $this->maxPoolOvershootNgn($requestedNgn);
    }

    public function assertPoolNgnFromStellarUsdc(
        SendraCentiivRampService $agg,
        float $stellarUsdcAmount,
        float $requestedNgn,
        float $toleranceNgn = 1.0,
        bool $checkOvershoot = true
    ): ?string {
        if (!SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()) {
            return null;
        }
        if ($requestedNgn <= 0 || $stellarUsdcAmount <= 0) {
            error_log(
                'Sendra pool assert invalid inputs: requested=' . $requestedNgn
                . ' stellar_usdc=' . $stellarUsdcAmount
            );

            return '❌ Invalid payout amount. Your wallet balance is unchanged.';
        }
        if ($toleranceNgn < 0) {
            $toleranceNgn = 0;
        }

        $q = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => 'NGN',
            'amount' => $stellarUsdcAmount,
        ]);
        if (!$q['success']) {
            return "❌ Could not verify the payout rate right now.\n\nYour wallet balance is unchanged. Try again in a minute.";
        }
        $sum = SendraCentiivRampService::summarizeQuoteNgn($q['data'], $stellarUsdcAmount);
        $recv = (float) ($sum['receive_ngn'] ?? 0);
        $poolFloor = SendraCentiivRampService::intermediaryPoolMinimumReceivedNgn($requestedNgn);
        $poolTol = $this->poolShortfallToleranceNgn();
        if ($recv + 1e-9 < $poolFloor - $poolTol) {
            error_log(
                'Sendra pool NGN shortfall at post-PIN: need=' . $poolFloor
                . ' quote=' . $recv . ' stellar_usdc=' . $stellarUsdcAmount
                . ' user_requested=' . $requestedNgn
            );

            return '❌ The rate moved and would not cover your *₦'
                . number_format($requestedNgn, 0, '.', ',')
                . "* payout.\n\nThis payout was cancelled. Your wallet was not debited — start again from WhatsApp.";
        }

        $overshootCeiling = $this->poolOvershootCeilingNgn($requestedNgn);
        if ($checkOvershoot && $recv > $overshootCeiling + 1e-9) {
            error_log(
                'Sendra pool NGN overshoot at post-PIN: need=' . $poolFloor
                . ' quote=' . $recv . ' ceiling=' . $overshootCeiling
                . ' stellar_usdc=' . $stellarUsdcAmount
                . ' user_requested=' . $requestedNgn
            );

            return '❌ The live rate would send too much to our settlement pool for this payout.'
                . "\n\nThis payout was cancelled. Your wallet was not debited — start again from WhatsApp.";
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $sellCtx
     * @return array<string, mixed>
     */
    public function applyIntermediaryNgnIfEnabled(array $body, array $sellCtx): array
    {
        $requested = (float) ($sellCtx['target_receive_ngn'] ?? $sellCtx['receive_ngn'] ?? 0);
        if ($requested <= 0 && !empty($sellCtx['receive_ngn_display'])) {
            $requested = (float) preg_replace('/[^\d.]/', '', (string) $sellCtx['receive_ngn_display']);
        }

        return SendraCentiivRampService::applyIntermediaryNgnOfframpBody(
            $body,
            (string) ($sellCtx['account_number'] ?? ''),
            (string) ($sellCtx['bank_code'] ?? ''),
            (string) ($sellCtx['account_name'] ?? ''),
            (string) ($sellCtx['bank_name'] ?? ''),
            isset($sellCtx['centiiv_banks_raw_row']) && is_array($sellCtx['centiiv_banks_raw_row'])
                ? $sellCtx['centiiv_banks_raw_row']
                : null,
            $requested
        );
    }
}
