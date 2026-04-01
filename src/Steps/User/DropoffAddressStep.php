<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class DropoffAddressStep extends BaseStep
{
    protected string $validationError = "⚠️ Please provide a valid drop-off address.\n\nExample: *45 Broad Street, Marina, Lagos Island*";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $requestId = $session['delivery_request_id'] ?? null;

        if (!$requestId) {
            return "We couldn't find your delivery request. Please start again.";
        }

        $address = trim($message);
        
        if (!$this->validateInput($address)) {
            return $this->validationError;
        }

        // Update the delivery request with dropoff address
        $this->deliveryRequestModel->update($requestId, [
            'dropoff_address' => $address
        ]);

        // Move to dropoff notes step
        $this->nextStep($phone, 'dropoff_location');

        return "Great! Drop-off address saved. 📍\n\n" .
               "Now, please share the drop-off location using WhatsApp's location sharing feature:\n" .
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
