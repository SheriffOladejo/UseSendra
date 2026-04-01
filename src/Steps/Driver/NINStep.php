<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class NinStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $nin  = preg_replace('/\D/', '', $message);
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $this->driverModel->update($phone, ['nin' => $nin]);

        $this->nextStep($phone, 'driver_id');

        return $t->get('nin_prompt');
    }

    public function validateInput(string $nin): bool
    {
        $lang = $this->session['language'] ?? 'en';
        $t    = new Translator($lang);

        if (preg_match('/^\d{11}$/', $nin)) {
            return true;
        }

        $this->validationError = $t->get('nin_invalid');
        return false;
    }
}