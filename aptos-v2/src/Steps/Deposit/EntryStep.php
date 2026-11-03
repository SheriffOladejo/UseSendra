<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Deposit entry — network selection step.
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
            'Which wallet do you want to use?',
            [
                'wallet_aptos' => 'Aptos',
                'wallet_stellar' => 'Stellar',
                'wallet_arc' => 'Arc',
                'dep_net_other' => 'Other network',
            ]
        );

        return StepResult::transition('deposit', 'network', [], ['action' => 'deposit_network_prompt']);
    }
}
