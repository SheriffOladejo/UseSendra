<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class PickupAddressStep extends BaseStep
{
    protected string $validationError = "⚠️ Please provide a valid pickup address.\n\nExample: *123 Allen Avenue, Ikeja, Lagos*";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];

        $address = trim($message);
        
        if (!$this->validateInput($address)) {
            return $this->validationError;
        }

        $user = $this->userModel->findByPhone($phone);

        $requestId = $this->deliveryRequestModel->create([
            'sender_phone' => $phone,
            'pickup_address' => $address,
            'user_id' => $user['id']
        ]);

        $this->sessionModel->update($phone, [
            'delivery_request_id' => $requestId
        ]);

        // Move to pickup notes step
        $this->nextStep($phone, 'pickup_location');

        return "Great! Pickup address saved. 📍\n\n" .
               "Now, please share the pickup location using WhatsApp's location sharing feature:\n" .
               "1. Tap the '+' icon\n" .
               "2. Select 'Location'\n" .
               "3. Choose 'Send your current location' or pick another location on the map";
    }

    public function validateInput(string $message): bool
    {
        $address = trim($message);
        
        // Require minimum length (at least 10 characters for a meaningful address)
        if (strlen($address) < 10) {
            return false;
        }

        // Must contain at least one letter and not be just numbers/symbols
        if (!preg_match('/[a-zA-Z]/', $address)) {
            return false;
        }

        return true;
    }
}