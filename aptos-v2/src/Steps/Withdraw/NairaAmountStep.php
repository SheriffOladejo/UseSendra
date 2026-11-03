<?php

declare(strict_types=1);

namespace AptosV2\Steps\Withdraw;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Buy\IntentParser;

/**
 * Collect fixed Naira payout amount before bank details.
 */
final class NairaAmountStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $amount = IntentParser::parseNairaAmount($ctx->message);
        if ($amount === null) {
            $this->reply(
                $ctx,
                "How much Naira do you want to withdraw?\nExample: *5000* or *10000 naira*\n\n*cancel* stops."
            );

            return StepResult::stay(['action' => 'withdraw_naira_amount_reprompt']);
        }

        $formatted = number_format($amount, 0, '.', ',');
        $this->reply(
            $ctx,
            "You'll receive *≈ ₦{$formatted}*.\n\n" .
            "Reply with your bank details:\n`0123456789 BankName`\nExample: `0123456789 GTBank`\n\n" .
            '*cancel* stops.'
        );

        return StepResult::transition('withdraw', 'await_bank', [
            'target_receive_ngn' => $amount,
        ], ['action' => 'withdraw_await_bank']);
    }
}
