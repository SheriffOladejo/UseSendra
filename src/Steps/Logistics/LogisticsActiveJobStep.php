<?php

namespace App\Steps\Logistics;

use App\Steps\BaseStep;

/**
 * Fallback when session is in a job-only state; main handling is in LogisticsMessageController.
 */
class LogisticsActiveJobStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        return '';
    }

    public function validateInput(string $message): bool
    {
        return false;
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $step = $this->session['current_step'] ?? '';
        if ($step === 'awaiting_location_share') {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                "📍 *Share your location*\n\n"
                . "Use WhatsApp's location feature:\n"
                . "1. Tap the '➕' icon (iOS) or the '📎' icon (Android)\n"
                . "2. Select 'Location'\n"
                . "3. Choose 'Send your current location' or pick a spot on the map"
            );

            return;
        }
        if ($step === 'awaiting_dropoff_pin') {
            $this->whatsappService->sendMessage(
                $phoneNumber,
                "🔢 *Dropoff code*\nThe receiver gives you *4 digits in person* — type only those digits here (e.g. *4829*).\n\n"
                . 'Other messages are not valid for this step.'
            );

            return;
        }
        $this->whatsappService->sendMessage($phoneNumber, 'Please follow the last instruction from Sendra for this job.');
    }
}
