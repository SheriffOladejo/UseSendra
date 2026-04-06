<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class PhoneStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];

        $lang = $session['language'] ?? 'en';
        error_log("Validating phone: '$phone' for language: $lang");
        $t = new Translator($lang);

        $this->driverModel->update($session['phone_number'], [
            'preferred_phone_number' => $phone
        ]);

        $this->nextStep($session['phone_number'], 'vehicle_type');

        $this->whatsappService->sendButtonMessage(
            $phone,
            $t->get('vehicle_prompt'),
            [
                "motorcycle" => $t->get('vehicle_motorcycle'),
                "keke"       => $t->get('vehicle_keke')
            ]
        );

        return $t->get('');
    }

    public function validateInput(string $phone): bool
    {
        if (preg_match('/^\d{11,13}$/', $phone)) {
            return true;
        }

        $lang = $this->session['language'] ?? 'en';
        $t = new Translator($lang);

        $this->validationError = $t->get('invalid_phone');
        return false;
    }
}