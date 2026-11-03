<?php

declare(strict_types=1);

namespace AptosV2\Steps\Withdraw;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Pick USDT/USDC before collecting a crypto sell amount.
 */
final class AssetStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $asset = $this->parseAsset($ctx->messageLower);
        if ($asset === null) {
            $this->buttons(
                $ctx,
                'Which token do you want to sell?',
                [
                    'withdraw_pick_usdt' => 'USDT',
                    'withdraw_pick_usdc' => 'USDC',
                ]
            );

            return StepResult::stay(['action' => 'withdraw_asset_reprompt']);
        }

        $this->reply(
            $ctx,
            "How much *{$asset}* do you want to withdraw?\nExample: *5* or *10.5*\n\n*cancel* stops."
        );

        return StepResult::transition('withdraw', 'await_crypto_amount', [
            'asset' => $asset,
        ], ['action' => 'withdraw_await_crypto_amount', 'asset' => $asset]);
    }

    private function parseAsset(string $messageLower): ?string
    {
        if ($messageLower === 'withdraw_pick_usdt' || $messageLower === 'usdt') {
            return 'USDT';
        }
        if ($messageLower === 'withdraw_pick_usdc' || $messageLower === 'usdc') {
            return 'USDC';
        }

        return null;
    }
}
