<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Buy\Guard;
use AptosV2\Steps\Buy\IntentParser;

/**
 * Collect Naira deposit amount, then hand off to buy asset pick + Centiiv order.
 */
final class AmountStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }
        if ($block = Guard::centiivConfiguredOrExit($ctx, $ctx->whatsapp())) {
            return $block;
        }

        $amount = IntentParser::parseNairaAmount($ctx->message);
        if ($amount === null) {
            $this->reply(
                $ctx,
                "How much Naira do you want to deposit?\nExample: *5000* or *10000 naira*\n\n*cancel* stops."
            );

            return StepResult::stay(['action' => 'deposit_amount_reprompt']);
        }

        $this->buttons(
            $ctx,
            "You'll deposit *≈ ₦" . number_format($amount, 0, '.', ',') . "*.\nWhich token should land in your wallet?",
            [
                'buy_pick_usdt' => 'USDT',
                'buy_pick_usdc' => 'USDC',
            ]
        );

        return StepResult::transition('buy', 'await_asset', [
            'fiat' => 'NGN',
            'pay_ngn' => $amount,
            'from_deposit' => true,
        ], ['action' => 'deposit_pick_asset']);
    }
}
