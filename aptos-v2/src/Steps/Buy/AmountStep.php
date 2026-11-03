<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Kyc\Gate as KycGate;

/**
 * Collect Naira spend amount (`await_amount`).
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
                "How much Naira do you want to spend?\nExample: *5000* or *10000 naira*\n\n*cancel* stops."
            );

            return StepResult::stay(['action' => 'buy_amount_reprompt']);
        }

        if ($kycBlock = KycGate::blockIfNeeded($ctx, $amount, [
            'flow' => 'buy',
            'step' => 'await_asset',
            'fiat' => 'NGN',
            'pay_ngn' => $amount,
        ])) {
            return $kycBlock;
        }

        $this->buttons(
            $ctx,
            "You'll pay *≈ ₦" . number_format($amount, 0, '.', ',') . "*.\nWhich token should land in your wallet?",
            [
                'buy_pick_usdt' => 'USDT',
                'buy_pick_usdc' => 'USDC',
            ]
        );

        return StepResult::transition('buy', 'await_asset', [
            'pay_ngn' => $amount,
        ], ['action' => 'buy_pick_asset']);
    }
}
