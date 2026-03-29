<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class NinStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $nin = preg_replace('/\D/', '', $message);

        $this->driverModel->update($session['phone_number'], [
            'nin' => $nin
        ]);

        $this->nextStep($session['phone_number'], 'driver_id');
        
        return "Nice, Now upload a picture of your *rider's card/drivers license or hackney permit*";
    }

    public function validateInput(string $nin): bool
    {
        if (preg_match('/^\d{11}$/', $nin)) {
            return true;
        }
        $this->validationError = "Please enter a valid 11-digit NIN number.";
        return false;
    }
}