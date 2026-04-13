<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class VehicleTypeStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $validTypes = [
            'motorcycle' => $t->get('vehicle_motorcycle'),
            'keke'       => $t->get('vehicle_keke')
        ];

        $msg = strtolower(trim($message));
        $msg = ltrim($msg, '/');

        if (!array_key_exists($msg, $validTypes)) {
            $this->whatsappService->sendButtonMessage(
                $phone,
                $t->get('invalid_vehicle_type'),
                $validTypes
            );
            return '';
        }

        // Save vehicle type
        $this->driverModel->update($phone, ['vehicle_type' => $msg]);

        // Move to next step
        $this->nextStep($phone, 'nin');

        return $t->get('vehicle_type_prompt');
    }

    public function validateInput(string $message): bool
    {
        $msg  = strtolower(trim($message));
        $msg  = ltrim($msg, '/');
        $lang = $this->session['language'] ?? 'en';
        $t    = new Translator($lang);

        $validKeys = ['motorcycle', 'keke'];

        if (!in_array($msg, $validKeys, true)) {
            $this->validationError = $t->get('invalid_vehicle_type');
            return false;
        }

        return true;
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $lang = $this->session['language'] ?? 'en';
        $t    = new Translator($lang);
        $validTypes = [
            'motorcycle' => $t->get('vehicle_motorcycle'),
            'keke'       => $t->get('vehicle_keke'),
        ];
        $this->whatsappService->sendButtonMessage(
            $phoneNumber,
            $t->get('invalid_vehicle_type'),
            $validTypes
        );
    }
}