<?php

declare(strict_types=1);

namespace AptosV2\Steps\Onboard;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Onboarding\OnboardingService;

/**
 * Creates a Sendra wallet after the user taps *Create an Account*.
 */
final class CreateAccountStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        $isLogistics = $ctx->messageLower === 'create_logistics_account';
        $name = trim($ctx->displayName) !== '' ? $ctx->displayName : 'there';

        /** @var OnboardingService $onboarding */
        $onboarding = $ctx->services->get('onboarding');
        $result = $onboarding->createAccount($ctx->from, $name, $isLogistics);

        return StepResult::complete([
            'action' => 'onboard_account_created',
            'success' => !empty($result['success']),
            'reason' => $result['reason'] ?? null,
        ]);
    }
}
