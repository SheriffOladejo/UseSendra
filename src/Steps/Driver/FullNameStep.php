<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class FullNameStep extends BaseStep
{
    /**
     * Handle rider name input
     */
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $name = trim($message);

        if (!$this->validateInput($name)) {
            return $t->get('enter_full_name');
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

        return $t->get('nice_to_meet_you', [
            'name' => $firstName
        ]);
    }

    /**
     * Validate name input
     */
    public function validateInput($message, $lang = 'en'): bool
    {
        $name = trim($message);
        $t = new Translator($lang);
        $this->validationError = $t->get('enter_full_name');
        return preg_match('/^[a-zA-Z]+\s+[a-zA-Z]+/', $name);
    }
}