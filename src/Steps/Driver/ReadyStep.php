<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

/**
 * Idle “home” for approved riders waiting for job offers (step key: rider_ready).
 * Do not change drivers.status here — job matching requires status = active.
 */
class ReadyStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        $this->whatsappService->sendMessage(
            $phone,
            "🚚 *Sendra rider*\n\n"
            . "You’re online for nearby offers.\n\n"
            . "• Use *Accept* / *Counter* / *Decline* on each job\n"
            . "• After pickup, the sender confirms you; at dropoff type the receiver’s *4-digit code* (digits only)\n"
            . "• If a customer asks for your location, send a map pin when prompted\n\n"
            . 'Say *hi* anytime for this reminder.'
        );

        return '';
    }

    public function validateInput($message): bool
    {
        return true;
    }
}
