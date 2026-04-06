<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class RiderApprovedStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        $message =
        "🚚 *Welcome to Sendra Logistics Network*\n\n".
        "You will receive delivery requests when packages are available near your location.\n\n".
        "When a request arrives you can:\n".
        "• Accept the delivery\n".
        "• Reject it\n".
        "• Send a counter offer\n\n".
        "💡 Make sure your location stays updated so you receive nearby deliveries.\n\n".
        "Need help?\n".
        "Chat our support team on WhatsApp:\n".
        "👉 https://wa.me/2347019240234";

        $this->whatsappService->sendMessage($phone, $message);

        return '';
    }

    public function validateInput(string $message): bool
    {
        return true; // Accept any message
    }
}