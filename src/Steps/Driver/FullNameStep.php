<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class FullNameStep extends BaseStep
{
    /**
     * Handle rider name input
     */
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];

        $name = trim($message);

        if (!$this->validateInput($name)) {
            return "Please enter your full name (e.g. *Chinedu Sani Adeyemi*).";
        }

        // Split name
        $parts = preg_split('/\s+/', $name);

        $firstName = ucfirst($parts[0]);
        $lastName  = isset($parts[1]) ? ucfirst($parts[1]) : '';

        $driver = $this->driverModel->findByPhone($phone);

        if (!$driver) {

            $this->driverModel->create(
                $phone,
                $firstName,
                $lastName,
                null
            );
        } else {

            $this->driverModel->update($phone, [
                'first_name' => $firstName,
                'last_name'  => $lastName
            ]);
        }

        // Move to next step
        $this->nextStep($phone, 'rider_phone');

        return "Nice to meet you *{$firstName}*! 🚴\n\nWhat phone number should customers call you on?";
    }

    /**
     * Validate name input
     */
    public function validateInput($message): bool
    {
        $name = trim($message);

        // Require at least first and last name
        return preg_match('/^[a-zA-Z]+\s+[a-zA-Z]+/', $name);
    }
}
