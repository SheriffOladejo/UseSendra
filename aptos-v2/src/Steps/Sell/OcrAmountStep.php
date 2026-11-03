<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\ImagePayload;

/**
 * After OCR: collect NGN amount, then USDT/USDC pick.
 */
final class OcrAmountStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($this->wantsCancel($ctx)) {
            $this->reply($ctx, 'Cancelled. Type *menu* for options.');

            return StepResult::complete(['action' => 'ocr_cancelled']);
        }

        $amount = ImagePayload::parseNairaAmount($ctx->message);
        if ($amount === null) {
            $this->reply(
                $ctx,
                "How much Naira? e.g. *10000* or *10000 naira*.\n*cancel* stops."
            );

            return StepResult::stay(['action' => 'ocr_amount_reprompt']);
        }

        $account = (string) $ctx->stateValue('preset_account', $ctx->stateValue('account_number', ''));
        $bank = (string) $ctx->stateValue('preset_bank_guess', $ctx->stateValue('bank_name', ''));
        $n = number_format($amount, 0, '.', ',');
        $this->buttons(
            $ctx,
            "Send *≈ ₦{$n}* to `{$account}` (*{$bank}*).\n\nWhich balance should we use?",
            [
                'sell_pick_usdt' => 'USDT',
                'sell_pick_usdc' => 'USDC',
            ]
        );

        return StepResult::transition('sell', 'asset', [
            'target_receive_ngn' => $amount,
        ], ['action' => 'ocr_proceed_asset']);
    }
}
