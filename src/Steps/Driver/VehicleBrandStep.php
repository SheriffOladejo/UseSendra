<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class VehicleBrandStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $brandModel = strtoupper(trim($message));

        // Save vehicle brand/model
        $this->driverModel->update($phone, [
            'vehicle_brand_model' => $brandModel
        ]);

        // Move to next step
        $this->nextStep($phone, 'rider_ready');

        // Send multilingual info message
        $this->whatsappService->sendMessage(
            $phone,
            $t->get('in_review_message')
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        $message = trim($message);

        $valid = preg_match('/^[A-Za-z0-9\s\-\/]{3,50}$/', $message) === 1;

        if (!$valid) {
            $lang = $this->session['language'] ?? 'en';
            $t = new Translator($lang);
            $this->validationError = $t->get('vehicle_brand_invalid');
        }

        return $valid;
    }
}