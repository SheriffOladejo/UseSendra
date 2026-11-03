<?php

declare(strict_types=1);

namespace AptosV2\Steps\Withdraw;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Withdraw entry — menu *Withdraw* button or `withdraw` command.
 */
final class EntryStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $this->buttons(
            $ctx,
            'How would you like to withdraw?',
            [
                'withdraw_sell_crypto' => 'Sell USDC/USDT',
                'withdraw_send_naira' => 'Send Naira',
            ]
        );

        return StepResult::transition('withdraw', 'method', [], ['action' => 'withdraw_method_prompt']);
    }
}
