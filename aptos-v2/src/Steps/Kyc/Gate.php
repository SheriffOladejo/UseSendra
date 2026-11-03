<?php

declare(strict_types=1);

namespace AptosV2\Steps\Kyc;

use App\Services\KycVerificationTokenService;
use App\Services\SendraKycService;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Blocks NGN ramp flows when the user exceeds their KYC tier limit.
 */
final class Gate
{
    /**
     * Returns a StepResult when the user must verify; null to continue.
     *
     * @param array<string, mixed> $resumeState Full flow state to restore after KYC
     */
    public static function blockIfNeeded(StepContext $ctx, float $ngnAmount, array $resumeState): ?StepResult
    {
        if ($ngnAmount <= 0) {
            return null;
        }

        $kyc = new SendraKycService();
        if ($kyc->canTransactNgn($ctx->user, $ngnAmount)) {
            return null;
        }

        $requiredTier = $kyc->requiredTierForAmount($ngnAmount);
        $targetTier = max($requiredTier, $kyc->nextTierToVerify($ctx->user));

        $ctx->whatsapp()->sendButtonMessage(
            $ctx->from,
            $kyc->tierUpgradePrompt($targetTier),
            [
                'kyc_start' => 'Start verification',
                'kyc_change_amount' => 'Change amount',
            ]
        );

        return StepResult::transition('kyc', 'prompt', [
            'required_tier' => $targetTier,
            'requested_ngn' => $ngnAmount,
            'resume' => $resumeState,
            'kyc_started' => false,
        ], ['action' => 'kyc_gate']);
    }

    public static function verificationUrl(string $phone, int $targetTier): string
    {
        return KycVerificationTokenService::buildUrl($phone, $targetTier, time() + 45 * 60);
    }
}
