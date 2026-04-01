<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;
use App\Utils\Translator;

class LocationPreferenceStep extends BaseStep
{
    protected string $validationError;

    public function handle(array $session, $message): string
    {
        $phone = $session['phone_number'];
        $lang  = $session['language'] ?? 'en';
        $t     = new Translator($lang);

        $this->validationError = $t->get('location_invalid');

        // Split the string into lat and lng
        $coordinates = explode(',', trim($message));

        $latitude  = (float) trim($coordinates[0]);
        $longitude = (float) trim($coordinates[1]);

        $this->driverModel->update($phone, [
            'preferred_latitude'  => $latitude,
            'preferred_longitude' => $longitude
        ]);

        $this->nextStep($phone, 'confirm_location');

        $mapUrl = "https://maps.google.com/?q={$latitude},{$longitude}";

        $this->whatsappService->sendMessage(
            $phone,
            $t->get('location_confirm_prompt')
        );

        $this->whatsappService->sendMessage($phone, $mapUrl);

        $this->whatsappService->sendButtonMessage(
            $phone,
            $t->get('location_confirm_button_prompt'),
            [
                "confirm_location" => $t->get('confirm_location')
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        $this->validationError = (new Translator($this->session['language'] ?? 'en'))->get('location_not_found');
        if (strpos($message, ',') === false) return false;

        $coordinates = explode(',', trim($message));

        if (count($coordinates) !== 2) return false;

        $latitude  = trim($coordinates[0]);
        $longitude = trim($coordinates[1]);

        if (!is_numeric($latitude) || !is_numeric($longitude)) return false;

        $latitude  = (float) $latitude;
        $longitude = (float) $longitude;

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) return false;

        return true;
    }
}