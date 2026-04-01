<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class ShowMenuStep extends BaseStep
{
    /**
     * Handle menu display and user choice
     */
    public function handle(array $session, $message): string
    {

        $phone = $session['phone_number'];
        $choice = $this->normalizeInput($message);

        switch ($choice) {
            case 'send':
            case '📦':
                $this->nextStep($phone, 'pickup_address');
                return "Nice, let's get this moving. 📦
                \nFirst, where are we picking up from?
                \nEnter the pickup address (e.g. *123 Allen Avenue, Ikeja, Lagos*):";

            case 'order':
            case 'track':
            case '📍':
                $this->nextStep($phone, 'track_order');
                return "Let me help you track your order. 📍\n\nPlease enter your order ID:";

            case 'help':
            case 'support':
            case '❓':
                $this->nextStep($phone, 'help_support');
                return "How can we help you today? ❓\n\nPlease describe your issue:";

            case 'history':
            case '🕘':
                $this->nextStep($phone, 'view_history');
                return "Loading your order history... 🕘";
        }

        return "I didn't understand that.\n\n" . $this->getMenuText();
    }

    /**
     * Validate menu choice input
     */
    public function validateInput($message): bool
    {
        $choice = $this->normalizeInput($message);

        $validChoices = [
            'send', '📦',
            'order', 'track', '📍',
            'help', 'support', '❓',
            'history', '🕘'
        ];

        $this->validationError = "I didn't understand that.\n\n" . $this->getMenuText();

        return in_array($choice, $validChoices);
    }

    /**
     * Get formatted menu text
     */
    private function getMenuText(): string
    {
        return "I can help you send packages fast, right here on WhatsApp. What would you like to do?\n\n" .
               "- 📦 To send a package — type *send*\n" .
               "- 📍 To track your order — type *order*\n" .
               "- ❓ To get help/support — type *help*\n" .
               "- 🕘 To see your history — type *history*";
    }

    private function normalizeInput($message): string
    {
        $message = strtolower(trim($message));

        // remove leading slash
        if (str_starts_with($message, '/')) {
            $message = substr($message, 1);
        }

        return $message;
    }
}