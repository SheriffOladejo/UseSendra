<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Handles destination choice: Sendra Wallet (1Click swap) vs Bank Account (Centiiv NGN cashout).
 */
final class DestinationStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $network = (string) ($ctx->state['deposit_network'] ?? 'Solana');
        $destination = null;

        if ($ctx->messageLower === 'dep_dest_wallet' || str_contains($ctx->messageLower, 'wallet') || str_contains($ctx->messageLower, 'sendra')) {
            $destination = 'wallet';
        } elseif ($ctx->messageLower === 'dep_dest_bank' || str_contains($ctx->messageLower, 'bank') || str_contains($ctx->messageLower, 'naira')) {
            $destination = 'bank';
        }

        if ($destination === null) {
            $this->buttons(
                $ctx,
                "Where should your *{$network}* deposit go?",
                [
                    'dep_dest_wallet' => '💳 Sendra Wallet',
                    'dep_dest_bank'   => '🏦 Bank Account',
                ]
            );

            return StepResult::stay(['action' => 'deposit_destination_reprompt']);
        }

        // If Solana, ask asset (USDC vs USDT). Otherwise default asset = USDC.
        if ($network === 'Solana') {
            $this->buttons(
                $ctx,
                "Which coin will you send from Solana?",
                [
                    'dep_asset_usdc' => 'USDC (Solana)',
                    'dep_asset_usdt' => 'USDT (Solana)',
                ]
            );

            return StepResult::transition(
                'deposit',
                'asset',
                [
                    'deposit_network' => $network,
                    'deposit_destination' => $destination,
                ],
                ['action' => 'deposit_asset_prompt']
            );
        }

        // Stellar / Base / Arbitrum default to USDC
        $asset = 'USDC';

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
