<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Select Solana deposit asset (USDC vs USDT).
 */
final class SolanaAssetStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $asset = null;
        if ($ctx->messageLower === 'deposit_sol_usdc' || str_contains($ctx->messageLower, 'usdc')) {
            $asset = 'USDC';
        } elseif ($ctx->messageLower === 'deposit_sol_usdt' || str_contains($ctx->messageLower, 'usdt')) {
            $asset = 'USDT';
        }

        if ($asset === null) {
            $this->buttons(
                $ctx,
                'Please select which Solana asset to deposit:',
                [
                    'deposit_sol_usdc' => 'USDC (Solana)',
                    'deposit_sol_usdt' => 'USDT (Solana)',
                ]
            );

            return StepResult::stay(['action' => 'deposit_solana_asset_reprompt']);
        }

        $this->reply(
            $ctx,
            "How much *{$asset}* do you plan to deposit from Solana?\n" .
            "Example: *20* or *50*\n\n" .
            "*cancel* stops."
        );

        return StepResult::transition(
            'deposit',
            'solana_amount',
            ['solana_asset' => $asset],
            ['action' => 'deposit_solana_amount_prompt', 'asset' => $asset]
        );
    }
}
