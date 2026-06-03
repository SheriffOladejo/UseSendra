<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

/**
 * Confirm step for rider "update location" flow.
 * Step key: rider_update_location_confirm
 */
class UpdateLocationConfirmStep extends BaseStep
{
    protected string $validationError = 'Please tap Confirm, or send a new WhatsApp location pin.';

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $m = strtolower(trim($message));

        if ($m === 'confirm_update_location') {
            $this->nextStep($phone, 'rider_ready');
            return "✅ Location updated successfully!\n\n"
                . "Tip: update your bus-stop location whenever you move around so you keep getting nearby requests.\n\n"
                . "Say *hi* anytime for the rider menu.";
        }

        // Allow sending a new location pin directly in this confirm step.
        $parsed = $this->parseLocationPayload($message);
        if ($parsed === null) {
            return $this->validationError;
        }
        [$lat, $lng] = $parsed;

        $this->driverModel->update($phone, [
            'preferred_latitude'  => $lat,
            'preferred_longitude' => $lng,
        ]);

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
        $m = strtolower(trim($message));
        if ($m === 'confirm_update_location') {
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
}

