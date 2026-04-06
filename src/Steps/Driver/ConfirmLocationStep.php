<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class ConfirmLocationStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $this->validationError = $t->get('confirm_location_error');

        // If user confirms
        if ($message === 'confirm_location') {

            $this->nextStep($phone, 'rider_approved');

            return   "✅ Location updated successfully!\n\n".
                "You are now ready to receive delivery requests near you.\n\n".
                "Say *hi* anytime to learn how the platform works.";
        }

        // If user sends a new location
        if (strpos($message, ',') !== false) {

            $coordinates = explode(',', trim($message));
            $latitude    = (float) trim($coordinates[0]);
            $longitude   = (float) trim($coordinates[1]);

            $this->driverModel->update($phone, [
                'preferred_latitude'  => $latitude,
                'preferred_longitude' => $longitude
            ]);

            $mapUrl = "https://maps.google.com/?q={$latitude},{$longitude}";

            $this->whatsappService->sendButtonMessage(
                $phone,
                $t->get('location_confirm_prompt') . "\n\n{$mapUrl}",
                [
                    "confirm_location" => $t->get('confirm_location')
                ]
            );

            return '';
        }

        return $this->validationError;
    }

    public function validateInput(string $message): bool
    {
        $lang = $this->session['language'] ?? 'en';
        $t    = new Translator($lang);

        $this->validationError = $t->get('confirm_location_error');

        if ($message === 'confirm_location') {
            return true;
        }

        if (strpos($message, ',') !== false) {
            $coordinates = explode(',', trim($message));

            if (count($coordinates) !== 2) return false;

            return is_numeric(trim($coordinates[0])) &&
                   is_numeric(trim($coordinates[1]));
        }

        return false;
    }
}