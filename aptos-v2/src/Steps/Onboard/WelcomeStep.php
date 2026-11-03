<?php

declare(strict_types=1);

namespace AptosV2\Steps\Onboard;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Onboarding\OnboardingService;

/**
 * Welcome message for unregistered users.
 */
final class WelcomeStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        $name = trim($ctx->displayName) !== '' ? $ctx->displayName : 'there';
        /** @var OnboardingService $onboarding */
        $onboarding = $ctx->services->get('onboarding');
        $voucherCount = $onboarding->pendingEscrowVoucherCount($ctx->from);

        if ($voucherCount > 0) {
            $plural = $voucherCount === 1 ? '' : 's';
            $ctx->whatsapp()->sendButtonMessage(
                $ctx->from,
                "Hi 👋 *{$name}*, I'm Sendra.\n\n" .
                "We found *{$voucherCount}* escrowed voucher{$plural} waiting for this phone number.\n\n" .
                "Create your Sendra account and we'll release it to your wallet automatically.",
                [
                    'create_account' => 'Create an Account',
                ]
            );
        } else {
            $ctx->whatsapp()->sendWelcomeMessage($ctx->from, $name);
        }

        return StepResult::complete(['action' => 'onboard_welcome']);
    }
}
