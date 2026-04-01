<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class LicenseNumberStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        // Save license number
        $this->driverModel->update($phone, [
            'license_number' => $message
        ]);

        $this->nextStep($phone, 'vehicle_picture');

        // Return translated prompt
        return $t->get('vehicle_picture_prompt');
    }

    public function validateInput(string $plateNumber): bool
    {
        $t = new Translator($this->session['language'] ?? 'en');
        // Normalize input
        $normalized = strtoupper(trim($plateNumber));

        // Remove spaces and dashes
        $stripped = str_replace(['-', ' '], '', $normalized);

        // Check length only
        if (strlen($stripped) > 5 && strlen($stripped) <= 10) {
            return true;
        }

        $this->validationError = $t->get('plate_number_invalid');
        return false;
    }

}