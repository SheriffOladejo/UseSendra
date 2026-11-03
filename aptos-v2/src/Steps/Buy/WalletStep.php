<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use App\Services\SendraDayaPolymarketService;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Buy\QuoteService;
use AptosV2\Support\ArcWalletService;
use AptosV2\Support\StellarWalletService;
use AptosV2\Support\WalletChoice;

final class WalletStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $chain = WalletChoice::parse($ctx->messageLower);
        if ($chain === null) {
            $asset = strtoupper((string) $ctx->stateValue('asset', 'USDC'));
            WalletChoice::prompt(
                $ctx,
                'Which wallet should receive the crypto?',
                [],
                in_array($asset, ['USDC', 'USDT'], true),
                true,
                (new SendraDayaPolymarketService())->isConfigured()
            );

            return StepResult::stay(['action' => 'buy_wallet_reprompt']);
        }

        if ($chain === WalletChoice::SOLANA) {
            $asset = strtoupper((string) $ctx->stateValue('asset', 'USDC'));
            if ($asset !== 'USDC') {
                $this->reply(
                    $ctx,
                    'Solana buy currently lands *USDC* at an address you paste. Choose *Aptos* or *Stellar* for USDT, or start again with *buy* USDC.'
                );

                return StepResult::stay(['action' => 'buy_solana_usdc_only']);
            }
            $this->reply(
                $ctx,
                "Sendra doesn't have a Solana wallet.\n\n"
                . "Paste the *Solana address* that should receive USDC after you pay Naira. Sendra sends it there.\n\n"
                . '*cancel* stops.'
            );

            return StepResult::transition('buy', 'await_solana_address', [
                'dest_chain' => 'solana',
                'asset' => $asset,
            ], ['action' => 'buy_solana_address_prompt']);
        }

        if ($chain === WalletChoice::POLYGON) {
            return $this->handOffToPolygon($ctx);
        }

        $userRow = $ctx->user;
        if ($chain === WalletChoice::ARC) {
            if (!ArcWalletService::hasAddress($ctx->user)) {
                return WalletChoice::missingArc($ctx);
            }
            $asset = strtoupper((string) $ctx->stateValue('asset', 'USDC'));
            if ($asset !== 'USDC') {
                $this->reply($ctx, 'Arc on-ramp lands *USDC* only. Choose *Aptos* or *Stellar* for USDT, or start again with *buy* USDC.');

                return StepResult::complete(['action' => 'buy_arc_usdc_only']);
            }
        }

        if ($chain === WalletChoice::STELLAR) {
            if (!StellarWalletService::hasAddress($ctx->user)) {
                return WalletChoice::missingStellar($ctx);
            }
            $asset = strtoupper((string) $ctx->stateValue('asset', 'USDC'));
            if (!in_array($asset, ['USDC', 'USDT'], true)) {
                $this->reply($ctx, 'Stellar on-ramp currently lands *USDC* or *USDT*. Tap one of those, or buy to Aptos.');

                return StepResult::complete(['action' => 'buy_stellar_usdc_only']);
            }
            $memo = \AptosV2\Support\StellarReceive::memoFromUser($ctx->user);
            if ($memo === null) {
                /** @var StellarWalletService $svc */
                $svc = $ctx->services->get('stellar_wallet');
                $out = $svc->provision($ctx->from, $ctx->user);
                $memo = isset($out['stellar_deposit_memo']) ? (string) $out['stellar_deposit_memo'] : null;
            }
            $userRow['stellar_deposit_memo'] = $memo ?? '';
        }

        /** @var QuoteService $quotes */
        $quotes = $ctx->services->get('buy_quote');
        $quoteMode = (string) $ctx->stateValue('quote_mode', 'naira');

        return ResultMapper::fromService($quotes->enqueueAndPrompt(
            $ctx->from,
            $userRow,
            $quoteMode,
            (string) $ctx->stateValue('asset', 'USDC'),
            isset($ctx->state['pay_ngn']) ? (float) $ctx->state['pay_ngn'] : null,
            isset($ctx->state['target_crypto']) ? (float) $ctx->state['target_crypto'] : null,
            $ctx->inboundWamid,
            $chain
        ));
    }

    private function handOffToPolygon(StepContext $ctx): StepResult
    {
        $picked = strtoupper((string) $ctx->stateValue('asset', 'USDT'));
        if ($picked === 'USDC') {
            /** @var QuoteService $quotes */
            $quotes = $ctx->services->get('buy_quote');
            $quotes->cancelActiveForPhone($ctx->from);
            $this->reply(
                $ctx,
                "✅ Transaction cancelled.\n\n" .
                "Polygon currently lands *USDT* only — USDC isn't supported there yet.\n\n" .
                "Type *buy* again and pick *USDT*, then *Polygon*."
            );

            return StepResult::complete(['action' => 'buy_polygon_usdc_unsupported']);
        }

        $svc = new SendraDayaPolymarketService();
        if (!$svc->isConfigured()) {
            $this->reply(
                $ctx,
                "Polygon buy isn't available right now. Choose *Stellar*, *Aptos*, or *Solana*, or type *cancel*."
            );

            return StepResult::stay(['action' => 'buy_polygon_unconfigured']);
        }
        $gate = $svc->assertOnchainPayout();
        if (!$gate['ok']) {
            $this->reply($ctx, (string) $gate['user_message'] . "\n\nChoose another wallet, or type *cancel*.");

            return StepResult::stay(['action' => 'buy_polygon_chain_blocked']);
        }

        $settlement = $svc->settlementAsset();
        $this->reply(
            $ctx,
            "Sendra doesn't have a Polygon wallet.\n\n"
            . "Polygon currently receives *{$settlement}*. Polymarket deposit addresses work; it converts to pUSD after arrival.\n\n"
            . "Paste the *Polygon `0x…` address* that should receive {$settlement}.\n\n"
            . '*cancel* stops.'
        );

        $patch = [
            'dest_chain' => 'polygon',
            'asset' => $settlement,
            'from_buy' => true,
            'fiat' => 'NGN',
            'quote_mode' => (string) $ctx->stateValue('quote_mode', 'naira'),
        ];
        if (isset($ctx->state['pay_ngn'])) {
            $patch['pay_ngn'] = (float) $ctx->state['pay_ngn'];
        }
        if (isset($ctx->state['target_crypto'])) {
            $patch['target_crypto'] = (float) $ctx->state['target_crypto'];
        }

        return StepResult::transition('polymarket', 'await_address', $patch, ['action' => 'buy_polygon_address_prompt']);
    }
}
