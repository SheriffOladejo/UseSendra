<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use App\Models\User;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\PinPromptService;

final class ChangePinStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if (!empty($ctx->user['pending_tx_id'])) {
            $this->reply(
                $ctx,
                '⚠️ You have a pending transaction. Type *cancel* first, then try `change pin` again.'
            );

            return StepResult::complete(['action' => 'change_pin_blocked_pending']);
        }

        /** @var PinPromptService $pinPrompts */
        $pinPrompts = $ctx->services->get('pin_prompts');

        if (empty($ctx->user['pin_hash'])) {
            $this->reply(
                $ctx,
                "⚠️ You haven't set a transaction PIN yet.\n\nSet one now to secure your account:"
            );
            /** @var User $userModel */
            $userModel = $ctx->services->get('user_model');
            $userModel->update($ctx->from, ['awaiting_pin_setup' => 1]);
            $pinPrompts->sendSetupPrompt($ctx->from);

            return StepResult::complete(['action' => 'change_pin_no_pin']);
        }

        $pinPrompts->sendChangePrompt($ctx->from);

        return StepResult::complete(['action' => 'change_pin_prompt']);
    }
}
