<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class LicenseNumberStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {

        $this->driverModel->update($session['phone_number'], [
            'license_number' => $message
        ]);

        $this->nextStep($session['phone_number'], 'vehicle_picture');

        return "We’re almost done, Take a picture or upload a clear image of your vehicle that shows the same plate number you shared.";
    }

    public function validateInput(string $licenseNumber): bool
    {
        $licenseNumber = strtoupper(trim($licenseNumber));
        // Alphanumeric, 8-15 characters
        if (preg_match('/^[A-Z0-9]{8,15}$/', $licenseNumber)) {
            return true;
        }
        $this->validationError = "Please enter a valid driver's license number.";
        return false;
    }
}