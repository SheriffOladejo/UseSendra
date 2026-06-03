<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class RiderApprovedStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $m = strtolower(trim((string) $message));

        // Button shortcut from rider menus
        if (in_array($m, ['update_location', 'update location'], true)) {
            $this->nextStep($phone, 'rider_update_location');
            return "📍 Please share your preferred bus-stop location so we can send delivery requests near you.\n\n"
                . "Use WhatsApp's location feature:\n"
                . "1. Tap the '➕' icon (iOS) or the '📎' icon (Android)\n"
                . "2. Select 'Location'\n"
                . "3. Search on the map or send your current location.";
        }

        // After approval, land rider on the idle home step for subsequent "hi"
        $this->nextStep($phone, 'rider_ready');

        $body =
            "🚚 *Welcome to Sendra Logistics Network*\n\n"
            . "You will receive delivery requests when packages are available near your location.\n\n"
            . "When a request arrives you can:\n"
            . "• Accept the delivery\n"
            . "• Reject it\n"
            . "• Send a counter offer\n\n"
            . "💡 Make sure your location stays updated the more you change location so you receive nearby deliveries.\n\n"
            . "Need help?\n"
            . "Chat our support team on WhatsApp:\n"
            . "👉 https://wa.me/2347019240234";

        $this->whatsappService->sendButtonMessage(
            $phone,
            $body,
            [
                'update_location' => '📍 Update location',
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return true; // Accept any message
    }
}