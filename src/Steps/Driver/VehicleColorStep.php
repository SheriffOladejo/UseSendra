<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class VehicleColorStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $color = ucfirst(strtolower(trim($message)));

        $this->driverModel->update($session['phone_number'], [
            'vehicle_color' => $color
        ]);

        $this->nextStep($session['phone_number'], 'vehicle_brand');

        return $t->get('vehicle_color_prompt');
    }

    public function validateInput(string $color): bool
    {
        if (preg_match('/^[a-zA-Z\s]{2,30}$/', $color)) {
            return true;
        }

        $this->validationError = (new Translator($this->session['language'] ?? 'en'))
            ->get('vehicle_color_invalid');

        return false;
    }
}