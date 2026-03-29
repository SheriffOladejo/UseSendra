<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class BvnStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $bvn = preg_replace('/\D/', '', $message);

        $this->driverModel->update($session['phone_number'], [
            'bvn' => $bvn
        ]);

        $this->nextStep($session['phone_number'], 'nin');

        return "Great! 🆔  

        Now, please provide your *NIN (National Identity Number)*.  

        Enter your 11-digit NIN:";
    }

    public function validateInput(string $bvn): bool
    {
        if (preg_match('/^\d{11}$/', $bvn)) {
            return true;
        }
        $this->validationError = "Please enter a valid 11-digit BVN number.";
        return false;
    }
}