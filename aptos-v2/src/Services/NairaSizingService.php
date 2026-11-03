<?php

declare(strict_types=1);

namespace AptosV2\Services;

use App\Services\SendraCentiivRampService;
use App\Services\WhatsAppService;
use AptosV2\Core\StepContext;
use AptosV2\Support\AptosBalanceService;
use AptosV2\Support\StellarWalletService;

/**
 * Fixed-fiat send-cash-out sizing orchestration for v2 steps (balance + WhatsApp + ctx patch).
 * Supports NGN, XOF (CFA MoMo), and KES (M-Pesa).
 *
 * When wallet_chain is not already chosen, tries each native wallet (Stellar first, then Aptos)
 * and uses the first single wallet that can fund the payout.
 */
final class NairaSizingService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private AptosBalanceService $balances,
    ) {
    }

    /**
     * Size crypto debit for a target NGN payout.
     *
     * @return array<string, mixed>|null Context patch on success; null on error (user notified).
     *         Returns {@see NairaSizingResult::LEGACY_REQUIRED} as string when caller should use legacy.
     */
    public function solve(StepContext $ctx, string $asset, float $targetNgn): array|string|null
    {
        $from = $ctx->from;
        $asset = \AptosV2\Support\StellarStable::display($asset);
        $fiat = strtoupper((string) $ctx->stateValue('fiat', 'NGN'));
        if ($fiat === '') {
            $fiat = 'NGN';
        }
        $sym = SendraCentiivRampService::currencySymbol($fiat);
        $this->whatsapp->sendMessage(
            $from,
            '⏳ Getting a rate for *≈ ' . $sym . number_format($targetNgn, 0, '.', ',') . "*…"
        );

        $agg = new SendraCentiivRampService();
        $explicitChain = isset($ctx->state['wallet_chain'])
            ? strtolower(trim((string) $ctx->state['wallet_chain']))
            : '';

        if ($explicitChain === 'stellar' || $explicitChain === 'aptos' || $explicitChain === 'arc') {
            $attempt = $this->attemptChain($ctx, $asset, $targetNgn, $explicitChain, $agg, $fiat);
            if ($attempt['ok']) {
                return $this->buildSuccessPatch($ctx, $asset, $targetNgn, $fiat, $sym, $attempt);
            }

            $this->whatsapp->sendMessage(
                $from,
                $attempt['result']->userMessage ?? '❌ Could not get a rate right now. Try again.'
            );

            return null;
        }

        // Auto-pick: Stellar first, then Aptos. Prefer the first wallet that can fund alone.
        $attempts = [];
        $order = strtoupper($asset) === 'USDC' ? ['stellar', 'arc', 'aptos'] : ['stellar', 'aptos'];
        foreach ($order as $chain) {
            $attempt = $this->attemptChain($ctx, $asset, $targetNgn, $chain, $agg, $fiat);
            $attempts[$chain] = $attempt;
            if ($attempt['ok']) {
                return $this->buildSuccessPatch($ctx, $asset, $targetNgn, $fiat, $sym, $attempt);
            }
        }

        $this->whatsapp->sendMessage($from, $this->autoPickFailureMessage($asset, $targetNgn, $fiat, $sym, $attempts));

        return null;
    }

    /**
     * @return array{
     *   ok: bool,
     *   chain: string,
     *   avail: float,
     *   result: NairaSizingResult,
     *   skip?: bool
     * }
     */
    private function attemptChain(
        StepContext $ctx,
        string $asset,
        float $targetNgn,
        string $chain,
        SendraCentiivRampService $agg,
        string $fiat
    ): array {
        $from = $ctx->from;
        $user = $ctx->user;

        if ($chain === 'stellar') {
            if (!StellarWalletService::hasAddress($user)) {
                return [
                    'ok' => false,
                    'chain' => 'stellar',
                    'avail' => 0.0,
                    'skip' => true,
                    'result' => NairaSizingResult::fail(
                        'no_stellar_wallet',
                        '❌ No Stellar wallet on file. Type *create stellar* first.'
                    ),
                ];
            }
            $stellarAddr = trim((string) ($user['stellar_address'] ?? ''));
            /** @var \AptosV2\Support\StellarBalanceService $stellarBal */
            $stellarBal = $ctx->services->get('stellar_balance');
            $balanceMap = $stellarBal->fetch($stellarAddr);
            if ($balanceMap === null) {
                return [
                    'ok' => false,
                    'chain' => 'stellar',
                    'avail' => 0.0,
                    'skip' => true,
                    'result' => NairaSizingResult::fail(
                        'balance_read_failed',
                        '❌ Could not read your Stellar balance. Try again.'
                    ),
                ];
            }
            $avail = (float) str_replace(',', '', $balanceMap[$asset] ?? '0');
            $result = NairaSizing::solveStellarDirect($asset, $avail, $targetNgn, $agg, NairaSizing::toleranceNgn(), $fiat);

            return [
                'ok' => $result->success && !$result->needsLegacyFallback(),
                'chain' => 'stellar',
                'avail' => $avail,
                'result' => $result,
            ];
        }

        if ($chain === 'arc') {
            if (strtoupper($asset) !== 'USDC' || !\AptosV2\Support\ArcWalletService::hasAddress($user)) {
                return [
                    'ok' => false,
                    'chain' => 'arc',
                    'avail' => 0.0,
                    'skip' => true,
                    'result' => NairaSizingResult::fail(
                        'no_arc_wallet',
                        '❌ No Arc USDC wallet on file. Type *create arc* first.'
                    ),
                ];
            }
            $arcAddr = trim((string) ($user['arc_address'] ?? ''));
            /** @var \AptosV2\Support\ArcBalanceService $arcBal */
            $arcBal = $ctx->services->get('arc_balance');
            $balanceMap = $arcBal->fetch($arcAddr);
            if ($balanceMap === null) {
                return [
                    'ok' => false,
                    'chain' => 'arc',
                    'avail' => 0.0,
                    'skip' => true,
                    'result' => NairaSizingResult::fail(
                        'balance_read_failed',
                        '❌ Could not read your Arc balance. Try again.'
                    ),
                ];
            }
            $avail = (float) str_replace(',', '', $balanceMap['USDC'] ?? '0');
            $result = NairaSizing::solveStellarDirect($asset, $avail, $targetNgn, $agg, NairaSizing::toleranceNgn(), $fiat, 'ARC', 'Arc');

            return [
                'ok' => $result->success && !$result->needsLegacyFallback(),
                'chain' => 'arc',
                'avail' => $avail,
                'result' => $result,
            ];
        }

        $aptosAddress = trim((string) ($user['aptos_address'] ?? ''));
        if ($aptosAddress === '') {
            return [
                'ok' => false,
                'chain' => 'aptos',
                'avail' => 0.0,
                'skip' => true,
                'result' => NairaSizingResult::fail(
                    'no_wallet',
                    '❌ Could not read your wallet. Try again.'
                ),
            ];
        }

        $balanceMap = $this->balances->fetch($aptosAddress);
        if ($balanceMap === null) {
            return [
                'ok' => false,
                'chain' => 'aptos',
                'avail' => 0.0,
                'skip' => true,
                'result' => NairaSizingResult::fail(
                    'balance_read_failed',
                    '❌ Could not read your balance. Try again.'
                ),
            ];
        }

        $avail = (float) str_replace(',', '', $balanceMap[$asset] ?? '0');
        $result = NairaSizing::solve(
            $asset,
            $avail,
            $targetNgn,
            $aptosAddress,
            $agg,
            $from,
            $fiat
        );

        return [
            'ok' => $result->success && !$result->needsLegacyFallback(),
            'chain' => 'aptos',
            'avail' => $avail,
            'result' => $result,
        ];
    }

    /**
     * @param array{ok: bool, chain: string, avail: float, result: NairaSizingResult} $attempt
     * @return array<string, mixed>|null
     */
    private function buildSuccessPatch(
        StepContext $ctx,
        string $asset,
        float $targetNgn,
        string $fiat,
        string $sym,
        array $attempt
    ): ?array {
        $from = $ctx->from;
        $chain = $attempt['chain'];
        $avail = $attempt['avail'];
        $solved = $attempt['result']->data;

        if ($avail + 1e-9 < (float) $solved['crypto_amount']) {
            $this->whatsapp->sendMessage($from, '❌ Balance changed. Try again.');

            return null;
        }

        $solved = NairaSizing::applyIntermediarySendNairaDisplay($targetNgn, $solved, $fiat);
        if (
            $fiat === 'NGN'
            && SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()
            && $targetNgn > 0
        ) {
            $poolQuoted = (float) ($solved['receive_ngn_pool'] ?? 0);
            $poolFloor = NairaSizing::poolSizingTarget($targetNgn);
            if ($poolQuoted > 0 && $poolQuoted + 1e-9 < $poolFloor) {
                $this->whatsapp->sendMessage(
                    $from,
                    '❌ Could not guarantee *' . $sym . number_format($targetNgn, 0, '.', ',') . "* to your account at the current rate.\nTry again or a slightly different amount."
                );

                return null;
            }
        }

        $patch = [
            'flow' => 'sell',
            'step' => 'await_send_naira_asset',
            'fiat' => $fiat,
            'target_receive_ngn' => $targetNgn,
            'preset_account' => (string) $ctx->stateValue('preset_account', ''),
            'preset_bank_guess' => (string) $ctx->stateValue('preset_bank_guess', ''),
            'bank_code' => (string) $ctx->stateValue('bank_code', ''),
            'bank_name' => (string) $ctx->stateValue('bank_name', ''),
            'xof_country' => (string) $ctx->stateValue('xof_country', ''),
            'crypto_amount' => $solved['crypto_amount'],
            'asset' => $asset,
            'wallet_chain' => $chain,
            'receive_ngn_display' => $solved['receive_ngn_display'],
            'send_naira_quick' => true,
        ];
        if (!empty($solved['sendra_quoted_receive_ngn'])) {
            $patch['sendra_quoted_receive_ngn'] = $solved['sendra_quoted_receive_ngn'];
        }
        if (!empty($solved['rate_hint'])) {
            $patch['send_naira_rate_line'] = 'Rate: *' . $solved['rate_hint'] . '*';
            $patch['rate_hint'] = $solved['rate_hint'];
        }
        if (!empty($solved['fee_hint'])) {
            $patch['fee_hint'] = $solved['fee_hint'];
        }
        if (!empty($solved['daya_rate_id'])) {
            $patch['daya_rate_id'] = $solved['daya_rate_id'];
        }
        if (!empty($solved['daya_rate'])) {
            $patch['daya_rate'] = $solved['daya_rate'];
        }
        if (!empty($solved['ramp'])) {
            $patch['ramp'] = $solved['ramp'];
        } else if (\App\Services\SendraAptosRamp::usesDayaForAptos() && $chain !== 'stellar' && $fiat === 'NGN') {
            $patch['ramp'] = 'daya';
        }
        $oneclickDry = $solved['oneclick_dry_quote_data'] ?? $solved['oneclick_quote_data'] ?? null;
        if (is_array($oneclickDry) && $oneclickDry !== []) {
            $patch['oneclick_dry_quote_data'] = $oneclickDry;
            $patch['oneclick_dry_est_usdc'] = $solved['oneclick_est_usdc'] ?? null;
        }
        if (!empty($solved['oneclick_live_quote_data']) && is_array($solved['oneclick_live_quote_data'])) {
            $patch['oneclick_live_quote_data'] = $solved['oneclick_live_quote_data'];
        }

        return $patch;
    }

    /**
     * @param array<string, array{ok: bool, chain: string, avail: float, result: NairaSizingResult, skip?: bool}> $attempts
     */
    private function autoPickFailureMessage(
        string $asset,
        float $targetNgn,
        string $fiat,
        string $sym,
        array $attempts
    ): string {
        $balanceCodes = ['insufficient_balance', 'no_balance'];
        $allBalanceIssues = true;
        $balanceLines = [];

        foreach (['stellar', 'arc', 'aptos'] as $chain) {
            $attempt = $attempts[$chain] ?? null;
            if ($attempt === null || !empty($attempt['skip'])) {
                continue;
            }
            $code = (string) ($attempt['result']->errorCode ?? '');
            if (!in_array($code, $balanceCodes, true)) {
                $allBalanceIssues = false;
            }
            $label = match ($chain) {
                'stellar' => 'Stellar',
                'arc' => 'Arc',
                default => 'Aptos',
            };
            $balanceLines[] = "{$label}: *" . number_format((float) $attempt['avail'], 2) . "* {$asset}";
        }

        if ($allBalanceIssues && $balanceLines !== []) {
            return '❌ Your balance isn’t enough for *≈ ' . $sym . number_format($targetNgn, 0, '.', ',') . "*.\n" .
                implode(' · ', $balanceLines) . '. Try a smaller amount.';
        }

        // Prefer Aptos error (last tried) when both failed for non-balance reasons; else first available.
        foreach (['aptos', 'arc', 'stellar'] as $chain) {
            $attempt = $attempts[$chain] ?? null;
            if ($attempt === null || !empty($attempt['skip'])) {
                continue;
            }
            $msg = $attempt['result']->userMessage ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        }

        foreach (['stellar', 'aptos'] as $chain) {
            $attempt = $attempts[$chain] ?? null;
            if ($attempt === null) {
                continue;
            }
            $msg = $attempt['result']->userMessage ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        }

        return '❌ Could not get a rate right now. Try again.';
    }
}
