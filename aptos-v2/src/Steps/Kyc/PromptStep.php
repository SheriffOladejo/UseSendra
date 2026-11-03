<?php

declare(strict_types=1);

namespace AptosV2\Steps\Kyc;

use App\Services\SendraKycService;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * KYC upgrade prompt — Start verification / Change amount.
 */
final class PromptStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if (in_array($ctx->messageLower, ['cancel', '/cancel', 'stop'], true)) {
            $this->reply($ctx, "Cancelled. Type *menu* for options.");

            return StepResult::complete(['action' => 'kyc_cancelled']);
        }

        if (\AptosV2\Steps\Buy\InteractiveRouter::isQuoteConfirmTap($ctx->message, $ctx->messageLower)) {
            $this->reply(
                $ctx,
                "That buy quote is no longer active here.\n\nType *cancel*, then start again with *buy*."
            );

            return StepResult::stay(['action' => 'kyc_stale_buy_quote']);
        }

        $kyc = new SendraKycService();
        $requested = (float) $ctx->stateValue('requested_ngn', 0);
        $requiredTier = (int) $ctx->stateValue('required_tier', 1);
        $userTier = $kyc->getUserTier($ctx->user);

        if ($userTier >= $requiredTier) {
            if ($requested > 0 && !$kyc->canTransactNgn($ctx->user, $requested)) {
                $needed = $kyc->requiredTierForAmount($requested);
                $this->reply(
                    $ctx,
                    'Your *' . $kyc->tierLabel($requiredTier) . '* is approved. '
                    . 'That transaction still needs *' . $kyc->tierLabel($needed) . '*. '
                    . 'Tap *Start verification* for the next tier, *Change amount*, or type *cancel*.'
                );

                return StepResult::transition('kyc', 'prompt', [
                    'required_tier' => $needed,
                    'requested_ngn' => $requested,
                    'resume' => $ctx->state['resume'] ?? null,
                    'kyc_started' => false,
                ], ['action' => 'kyc_tier_partial']);
            }

            return $this->resumeOrComplete($ctx);
        }

        $targetTier = (int) $ctx->stateValue('required_tier', 1);

        if ($ctx->messageLower === 'kyc_change_amount') {
            $resume = $ctx->state['resume'] ?? null;
            if (is_array($resume)) {
                $flow = (string) ($resume['flow'] ?? '');
                $step = (string) ($resume['step'] ?? '');
                if ($flow !== '' && $step !== '') {
                    unset($resume['flow'], $resume['step']);
                    $this->reply($ctx, 'How much would you like to transact instead? (e.g. *30000*)');

                    return StepResult::transition($flow, $step, $resume, ['action' => 'kyc_change_amount']);
                }
            }

            $this->reply($ctx, 'Type *buy* or *sell* to start again with a different amount.');

            return StepResult::complete(['action' => 'kyc_change_amount']);
        }

        if ($ctx->messageLower === 'kyc_start' || in_array($ctx->messageLower, ['yes', 'y', 'kyc_yes'], true)) {
            $url = Gate::verificationUrl($ctx->from, $targetTier);
            $ctx->whatsapp()->sendCTAUrlButton(
                $ctx->from,
                'Tap *Start verification* below to open our secure verification page.',
                'Start verification',
                $url
            );

            if ($targetTier === 1) {
                $this->reply(
                    $ctx,
                    "Thank you. Complete Tier 1 on the page — I'll notify you here once it's approved."
                );
            } else {
                $this->reply(
                    $ctx,
                    "Thank you. We're reviewing your details. I'll notify you here once Tier {$targetTier} is approved."
                );
            }

            return StepResult::transition('kyc', 'prompt', [
                'kyc_started' => true,
            ], ['action' => 'kyc_link_sent']);
        }

        $this->reply($ctx, 'Tap *Start verification* or *Change amount*, or type *cancel*.');

        return StepResult::stay(['action' => 'kyc_reprompt']);
    }

    private function resumeOrComplete(StepContext $ctx): StepResult
    {
        $resume = $ctx->state['resume'] ?? null;
        if (is_array($resume)) {
            $flow = (string) ($resume['flow'] ?? '');
            $step = (string) ($resume['step'] ?? '');
            if ($flow !== '' && $step !== '') {
                $patch = $resume;
                unset($patch['flow'], $patch['step']);
                $this->reply($ctx, '✅ Verification complete. Continuing where you left off…');

                return StepResult::transition($flow, $step, $patch, ['action' => 'kyc_resumed']);
            }
        }

        $this->reply($ctx, "✅ You're verified. Type *menu* for options.");

        return StepResult::complete(['action' => 'kyc_already_satisfied']);
    }
}
