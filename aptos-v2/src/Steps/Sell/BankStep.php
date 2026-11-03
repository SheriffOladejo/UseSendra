<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Collects bank account (`0123456789 GTBank`) then queues PIN.
 */
final class BankStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($this->wantsCancel($ctx)) {
            $this->reply($ctx, "Cancelled. Type *menu* for options.");

            return StepResult::complete(['action' => 'sell_cancelled']);
        }

        $fiat = strtoupper((string) $ctx->stateValue('fiat', 'NGN'));
        $cleanMsg = trim($ctx->message);

        if ($fiat === 'XOF') {
            if (preg_match('/^(\d{8,15})(?:\s+(.+))?$/u', $cleanMsg, $m)) {
                $accountNumber = $m[1];
                $networkGuess = !empty($m[2]) ? trim($m[2]) : (string) $ctx->stateValue('bank_code', 'MTN');

                /** @var ExecutionService $executor */
                $executor = $ctx->services->get('sell_execution');

                return $executor->finalizeBankAndQueuePin($ctx, $accountNumber, $networkGuess);
            }

            $this->reply(
                $ctx,
                "Enter your MoMo phone number (8–15 digits, with country code).\nExample: `22890123456`\n\nType *cancel* to stop."
            );

            return StepResult::stay(['action' => 'sell_bank_format']);
        }

        if (!preg_match('/^(\d{8,15})\s+(.+)$/u', $cleanMsg, $m)) {
            $example = $fiat === 'KES'
                ? "Use: `0758901726 Mpesa` or `0758901726 KCB`"
                : "Use: `0123456789 BankName` (10-digit NGN account)";

            $this->reply(
                $ctx,
                "{$example}.\nType *cancel* to stop."
            );

            return StepResult::stay(['action' => 'sell_bank_format']);
        }

        /** @var ExecutionService $executor */
        $executor = $ctx->services->get('sell_execution');

        return $executor->finalizeBankAndQueuePin($ctx, $m[1], trim($m[2]));
    }
}
