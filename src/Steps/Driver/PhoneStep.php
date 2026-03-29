<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class PhoneStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = preg_replace('/\D/', '', $message);

        $this->driverModel->update($session['phone_number'], [
            'preferred_phone_number' => $phone
        ]);

        $this->nextStep($session['phone_number'], 'rider_location_preference');

        return "Excellent🎯! Where do you usually operate from?  (This will help us route orders to you easily). 
        \n\nUse Whatsapp's location📍 feature to select the location on the map
        \n1. Tap the '+' icon
        \n2. Select 'Location'
        \n3. Choose 'Send your current location' or pick a spot on the map
        \n\nNo worries, you can always change it anytime";

    }

    public function validateInput(string $phone): bool
    {
        if (preg_match('/^\d{11,13}$/', $phone)) {
            return true;
        }

        $this->validationError = "Please enter a valid phone number.";
        return false;
    }
}
