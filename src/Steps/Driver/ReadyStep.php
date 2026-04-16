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
        $m = strtolower(trim((string) $message));

        if (in_array($m, ['update_location', 'update location'], true)) {
            $this->nextStep($phone, 'rider_update_location');
            return "📍 Please share your preferred bus-stop location so we can send delivery requests near you.\n\n"
                . "Use WhatsApp's location feature:\n"
                . "1. Tap the '➕' icon (iOS) or the '📎' icon (Android)\n"
                . "2. Select 'Location'\n"
                . "3. Search on the map or send your current location.";
        }

        $this->whatsappService->sendButtonMessage(
            $phone,
            "🚚 *Sendra rider*\n\n"
            . "You’re online for nearby offers.\n\n"
            . "• Use *Accept* / *Counter* / *Decline* on each job\n"
            . "• After pickup, the sender confirms you; at dropoff type the receiver’s *4-digit code* (digits only)\n"
            . "• If a customer asks for your location, send a map pin when prompted\n\n"
            . "Keep your bus-stop location updated as you move so you get nearby requests.",
            [
                'update_location' => '📍 Update location',
            ]
        );

        return '';
    }

    public function validateInput($message): bool
    {
        return true;
    }
}
