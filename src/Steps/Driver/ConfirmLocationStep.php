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
                "Tip: update your bus-stop location anytime you move around so you keep getting nearby requests.\n\n".
                "Say *hi* anytime to learn how the platform works.";
        }

        // If user sends a new location
        $parsed = $this->parseLocationPayload((string) $message);
        if ($parsed !== null) {
            [$latitude, $longitude] = $parsed;

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

        return $this->parseLocationPayload($message) !== null;
    }

    /**
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
        return [(float) $lat, (float) $lng];
    }

    public function sendValidationFailureFeedback(string $phoneNumber): void
    {
        $lang = $this->session['language'] ?? 'en';
        $t    = new Translator($lang);
        $body = trim($this->getValidationError());
        if ($body === '') {
            $body = $t->get('confirm_location_error');
        }
        $this->whatsappService->sendMessage($phoneNumber, $body);

        $driver = $this->driverModel->findByPhone($phoneNumber);
        if (
            $driver
            && isset($driver['preferred_latitude'], $driver['preferred_longitude'])
            && $driver['preferred_latitude'] !== null
            && $driver['preferred_longitude'] !== null
            && $driver['preferred_latitude'] !== ''
            && $driver['preferred_longitude'] !== ''
        ) {
            $lat    = (float) $driver['preferred_latitude'];
            $lng    = (float) $driver['preferred_longitude'];
            $mapUrl = "https://maps.google.com/?q={$lat},{$lng}";
            $this->whatsappService->sendButtonMessage(
                $phoneNumber,
                $t->get('location_confirm_prompt') . "\n\n{$mapUrl}",
                [
                    'confirm_location' => $t->get('confirm_location'),
                ]
            );
        }
    }
}