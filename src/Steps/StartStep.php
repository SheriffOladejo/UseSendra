<?php

namespace App\Steps;

use App\Steps\BaseStep;

class StartStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        // Move user to role selection
        $this->nextStep($phone, 'choose_role');

        $name = $this->getUsername() ?? 'there';

        // Send buttons
        $this->whatsappService->sendButtonMessage(
            $phone,
            "Hello 🙋🏽‍♀️ $name! Welcome to *Sendra Courier* \n\nLet’s get you set up real quick.\n\nAre you here to send packages or deliver them?",
            [
                "sender" => "📦 Send Package",
                "rider"  => "🏍 Delivery Rider"
            ]
        );

        return '';
    }

    public function validateInput($message): bool
    {
        return true;
    }
}