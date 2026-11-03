<?php

declare(strict_types=1);

namespace AptosV2\Steps\Swap;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

final class ConfirmStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($this->wantsCancel($ctx)) {
            $this->reply($ctx, "Cancelled. Type *menu* for options.");

            return StepResult::complete(['action' => 'swap_cancelled']);
        }

        if (!$this->meansConfirm($ctx)) {
            $this->buttons(
                $ctx,
                "Please tap *Confirm* to proceed, or *Cancel* to stop.",
                [
                    'swap_confirm' => '✅ Confirm',
                    'cancel'       => '✖️ Cancel',
                ]
            );

            return StepResult::stay(['action' => 'swap_confirm_reprompt']);
        }

        /** @var ExecutionService $executor */
        $executor = $ctx->services->get('swap_execution');

        return $executor->executeAfterConfirm($ctx);
    }

    private function meansConfirm(StepContext $ctx): bool
    {
        return in_array($ctx->messageLower, ['swap_confirm', 'confirm', 'yes', 'y'], true);
    }
}
