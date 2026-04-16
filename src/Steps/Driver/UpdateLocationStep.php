<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

/**
 * Rider self-serve "update location" flow (separate from onboarding).
 * Step key: rider_update_location
 */
class UpdateLocationStep extends BaseStep
{
    protected string $validationError =
        "📍 We couldn't get your location.\n\n"
        . "Please share your location using WhatsApp:\n"
        . "1. Tap the '➕' icon (iOS) or the '📎' icon (Android)\n"
        . "2. Select 'Location'\n"
        . "3. Send your current location or pick a spot on the map";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];

        $parsed = $this->parseLocationPayload($message);
        if ($parsed === null) {
            return $this->validationError;
        }
        [$lat, $lng] = $parsed;

        $this->driverModel->update($phone, [
            'preferred_latitude'  => $lat,
            'preferred_longitude' => $lng,
        ]);

        $this->nextStep($phone, 'rider_update_location_confirm');

        $mapUrl = "https://maps.google.com/?q={$lat},{$lng}";
        $this->whatsappService->sendButtonMessage(
            $phone,
            "Is this your preferred bus-stop location📍?\n\n{$mapUrl}\n\nIf correct, tap Confirm. Otherwise send a new location.",
            [
                'confirm_update_location' => 'Confirm',
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
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

        // Legacy: lat,lng
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

