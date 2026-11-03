<?php

declare(strict_types=1);

namespace AptosV2\Steps\Withdraw;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Sell\Guard;

/**
 * Pick withdraw method — sell crypto vs fixed-Naira bank payout.
 */
final class MethodStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        if ($ctx->messageLower === 'withdraw_sell_crypto') {
            if ($block = Guard::entryBlock($ctx, $ctx->whatsapp())) {
                return $block;
            }

            $this->buttons(
                $ctx,
                'Which token do you want to sell?',
                [
                    'withdraw_pick_usdt' => 'USDT',
                    'withdraw_pick_usdc' => 'USDC',
                ]
            );

            return StepResult::transition('withdraw', 'asset', ['fiat' => 'NGN'], ['action' => 'withdraw_pick_asset']);
        }

        if ($ctx->messageLower === 'withdraw_send_naira') {
            if ($block = Guard::entryBlock($ctx, $ctx->whatsapp())) {
                return $block;
            }

            $this->reply(
                $ctx,
                "How much Naira do you want to withdraw?\nExample: *5000* or *10000 naira*\n\n*cancel* stops."
            );

            return StepResult::transition('withdraw', 'await_naira_amount', ['fiat' => 'NGN'], ['action' => 'withdraw_await_naira']);
        }

        $this->buttons(
            $ctx,
            'How would you like to withdraw?',
            [
                'withdraw_sell_crypto' => 'Sell USDC/USDT',
                'withdraw_send_naira' => 'Send Naira',
            ]
        );

        return StepResult::stay(['action' => 'withdraw_method_reprompt']);
    }
}
