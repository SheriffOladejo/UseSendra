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

        $parsed = $this->parseLocationPayload((string) $message);
        if ($parsed === null) {
            return $this->validationError;
        }
        [$latitude, $longitude] = $parsed;

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
        return $this->parseLocationPayload($message) !== null;
    }

    /**
     * Accept either:
     * - JSON payload from webhook: {"location":{"latitude":..,"longitude":..}}
     * - Legacy text: "lat,lng"
     *
     * @return array{0:float,1:float}|null
     */
    private function parseLocationPayload(string $message): ?array
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        // JSON location payload from webhook
        if ($message[0] === '{') {
            $j = json_decode($message, true);
            if (is_array($j) && isset($j['location'])) {
                $loc = $j['location'];
                $lat = $loc['latitude'] ?? null;
                $lng = $loc['longitude'] ?? null;
                if (is_numeric($lat) && is_numeric($lng)) {
                    $latF = (float) $lat;
                    $lngF = (float) $lng;
                    if ($latF >= -90 && $latF <= 90 && $lngF >= -180 && $lngF <= 180) {
                        return [$latF, $lngF];
                    }
                }
            }
        }

        // Legacy lat,lng
        if (!str_contains($message, ',')) {
            return null;
        }
        $parts = explode(',', $message, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $lat = trim($parts[0]);
        $lng = trim($parts[1]);
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
            return null;
        }
        return [$latF, $lngF];
    }
}