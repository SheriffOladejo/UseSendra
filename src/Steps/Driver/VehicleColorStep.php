<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class VehicleColorStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $color = ucfirst(strtolower(trim($message)));

        $this->driverModel->update($session['phone_number'], [
            'vehicle_color' => $color
        ]);

        $this->nextStep($session['phone_number'], 'vehicle_brand');

        return "Perfect! 📝\n\nNext, provide the brand of your vehicle. For example: Bajaj, TVS-HLX";
    }

    public function validateInput(string $color): bool
    {
        // Only letters and spaces, 2-30 characters
        if (preg_match('/^[a-zA-Z\s]{2,30}$/', $color)) {
            return true;
        }
        $this->validationError = "Please enter a valid vehicle color (letters only, e.g., 'Black', 'Silver', 'Red').";
        return false;
    }
}