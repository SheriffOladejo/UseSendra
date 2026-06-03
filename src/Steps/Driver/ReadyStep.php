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

        $this->whatsappService->sendListMessage(
            $phone,
            "🚚 *Sendra rider* — you’re online\n\n"
            . "💰 *Wallet* — tap below for balance, add money, withdraw, or activity history.",
            'Wallet',
            [
                'balance' => 'Check balance',
                'fund' => 'Add money',
                'withdraw' => 'Withdraw',
                'history' => 'Activity history',
            ]
        );

        $this->whatsappService->sendButtonMessage(
            $phone,
            "*Jobs*\n"
            . "• *Accept* / *Counter* / *Decline* on each offer\n"
            . "• Sender confirms you at pickup (QR photo)\n"
            . "• At dropoff, type the receiver’s *4-digit code*\n"
            . "• Share a map pin if the customer asks for your location\n\n"
            . "Update your bus-stop when you move so offers stay nearby.",
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
