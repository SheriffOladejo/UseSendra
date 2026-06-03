<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;
use App\Utils\VehicleTypeHelper;

class VehicleTypeStep extends BaseStep
{
    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $validTypes = VehicleTypeHelper::driverButtonLabels($t);

        $msg = strtolower(trim($message));
        $msg = ltrim($msg, '/');
        $normalized = VehicleTypeHelper::normalize($msg);

        if ($normalized === null) {
            $this->whatsappService->sendButtonMessage(
                $phone,
                $t->get('invalid_vehicle_type'),
                $validTypes
            );
            return '';
        }

        $this->driverModel->update($phone, ['vehicle_type' => $normalized]);

        $this->nextStep($phone, 'nin');

        if ($normalized === VehicleTypeHelper::BICYCLE) {
            return $t->get('bicycle_onboarding_nin_prompt');
        }

        return $t->get('vehicle_type_prompt');
    }

    public function validateInput(string $message): bool
    {
        $msg  = strtolower(trim($message));
        $msg  = ltrim($msg, '/');
        $lang = $this->session['language'] ?? 'en';
        $t    = new Translator($lang);

        if (VehicleTypeHelper::normalize($msg) === null) {
            $this->validationError = $t->get('invalid_vehicle_type');
            return false;
        }

        return true;
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $lang = $this->session['language'] ?? 'en';
        $t    = new Translator($lang);
        $this->whatsappService->sendButtonMessage(
            $phoneNumber,
            $t->get('invalid_vehicle_type'),
            VehicleTypeHelper::driverButtonLabels($t)
        );
    }
}
