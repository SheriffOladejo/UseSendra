<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Asset selection step for Solana (USDC vs USDT) or confirmation for single-asset networks.
 */
final class AssetSelectionStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $network = (string) ($ctx->state['deposit_network'] ?? 'Solana');
        $destination = (string) ($ctx->state['deposit_destination'] ?? 'wallet');

        $asset = null;
        if ($ctx->messageLower === 'dep_asset_usdt' || str_contains($ctx->messageLower, 'usdt')) {
            $asset = 'USDT';
        } elseif ($ctx->messageLower === 'dep_asset_usdc' || str_contains($ctx->messageLower, 'usdc')) {
            $asset = 'USDC';
        }

        if ($asset === null) {
            $this->buttons(
                $ctx,
                "Please select which token you will send from *{$network}*:",
                [
                    'dep_asset_usdc' => 'USDC',
                    'dep_asset_usdt' => 'USDT',
                ]
            );

            return StepResult::stay(['action' => 'deposit_asset_reprompt']);
        }

        if ($destination === 'wallet') {
            $this->reply(
                $ctx,
                "How much *{$asset}* do you plan to deposit from *{$network}*?\n" .
                "Example: *50* or *300*\n\n" .
                "*cancel* stops."
            );

            return StepResult::transition(
                'deposit',
                '1click_amount',
                [
                    'deposit_network' => $network,
                    'deposit_destination' => $destination,
                    'deposit_asset' => $asset,
                ],
                ['action' => 'deposit_1click_amount_prompt']
            );
        }

        // Bank destination
        $this->reply(
            $ctx,
            "How much *{$asset}* on *{$network}* do you want to convert to Naira for bank payout?\n" .
            "Example: *50* or *300*\n\n" .
            "*cancel* stops."
        );

        return StepResult::transition(
            'deposit',
            'bank_amount',
            [
                'deposit_network' => $network,
                'deposit_destination' => $destination,
                'deposit_asset' => $asset,
            ],
            ['action' => 'deposit_bank_amount_prompt']
        );
    }
}
