<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\NairaSizingResult;
use AptosV2\Services\NairaSizingService;

/**
 * USDT/USDC pick for fixed-NGN send-naira cash-out.
 */
final class AssetStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($this->wantsCancel($ctx)) {
            $this->reply($ctx, "Cancelled. Type *menu* for options.");

            return StepResult::complete(['action' => 'sell_cancelled']);
        }

        $asset = $this->parseAsset($ctx->messageLower);
        if ($asset === null) {
            $this->buttons(
                $ctx,
                "Tap *USDT* or *USDC*, or type *USDT* / *USDC*.\n*cancel* stops.",
                [
                    'sell_pick_usdt' => 'USDT',
                    'sell_pick_usdc' => 'USDC',
                ]
            );

            return StepResult::stay(['action' => 'sell_send_naira_asset_reprompt']);
        }

        $targetNgn = (float) $ctx->stateValue('target_receive_ngn', 0);

        /** @var NairaSizingService $sizing */
        $sizing = $ctx->services->get('sell_naira_sizing');
        $sized = $sizing->solve($ctx, $asset, $targetNgn);

        if ($sized === NairaSizingResult::LEGACY_REQUIRED || $sized === null) {
            if ($sized === NairaSizingResult::LEGACY_REQUIRED) {
                $this->reply($ctx, '❌ Could not size this cash-out automatically. Try again, or *sell 5 USDC*.');
            }

            return StepResult::stay(['action' => 'sell_send_naira_sizing_failed']);
        }

        /** @var ExecutionService $execution */
        $execution = $ctx->services->get('sell_execution');

        return $execution->finalizeSendNairaAfterSizing($ctx, $sized);
    }

    private function parseAsset(string $messageLower): ?string
    {
        if ($messageLower === 'sell_pick_usdt' || $messageLower === 'usdt' || $messageLower === 'usdt0') {
            return 'USDT';
        }
        if ($messageLower === 'sell_pick_usdc' || $messageLower === 'usdc') {
            return 'USDC';
        }

        return null;
    }
}
