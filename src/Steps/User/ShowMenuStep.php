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
            case 'shop':
            case 'buy':
                $this->nextStep($phone, 'shop_menu');
                return "🛍️ *Sendra Shop*\n\n"
                    . "• *browse* — stores\n"
                    . "• *cart* — multi-store cart\n"
                    . "• *checkout* — pay online\n"
                    . "• *orders* — your purchases\n"
                    . "• *sell* — open a store\n"
                    . "• *send* — delivery only";

            case 'send':
            case '📦':
                // Create a new draft request (short flow starts with pickup pin)
                $user = $this->userModel->findByPhone($phone);
                if ($user) {
                    $requestId = $this->deliveryRequestModel->create([
                        'user_id' => $user['id'],
                        'sender_phone' => $phone,
                    ]);
                    if ($requestId) {
                        $this->sessionModel->update($phone, ['delivery_request_id' => $requestId]);
                    }
                }
                $this->nextStep($phone, 'pickup_location');
                return "Nice, let’s get this moving.\n\n"
                    . "First, where are we picking up from?\n\n"
                    . "Please share the pickup location using WhatsApp's location sharing feature:\n"
                    . "1. Tap the '➕' icon (iOS) or '📎' (Android)\n"
                    . "2. Select 'Location'\n"
                    . "3. Search on the map or send your current location.";

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

            case 'hi':
            case 'hello':
            case 'hey':
                return $this->getMenuText();
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
            'shop', 'buy',
            'send', '📦',
            'order', 'track', '📍',
            'help', 'support', '❓',
            'history', '🕘',
            'hi', 'hello', 'hey',
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
               "- 🛍️ To shop (multi-store) — type *shop*\n" .
               "- 📦 To send a package — type *send*\n" .
               "- 📍 To track your order — type *order*\n" .
               "- ❓ To get help/support — type *help*\n" .
               "- 🕘 To see your history — type *history*\n" .
               "- 🔄 Stuck on an old search? — type *restart*\n"
               . '- 📍 *track* — during an active job, ask your rider for their map location';
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