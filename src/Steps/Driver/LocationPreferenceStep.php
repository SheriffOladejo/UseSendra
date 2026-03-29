<?php

namespace App\Steps\Driver;

use App\Steps\BaseStep;

class LocationPreferenceStep extends BaseStep
{
    protected string $validationError = "📍 We couldn't get your location. 

    Please use WhatsApp's location sharing feature:
    1. Tap the '+' icon
    2. Select 'Location'
    3. Choose 'Send your current location' or pick a spot on the map

    This helps us send you nearby ride requests!";

    public function handle(array $session, $message): string
    {
        // Split the string into lat and lng
        $coordinates = explode(',', trim($message));
        
        $latitude = (float) trim($coordinates[0]);
        $longitude = (float) trim($coordinates[1]);

        $phone = $session['phone_number'];

        $this->driverModel->update($phone, [
            'preferred_latitude'  => $latitude,
            'preferred_longitude' => $longitude
        ]);

        $this->nextStep($phone, 'vehicle_type');

        $this->whatsappService->sendButtonMessage(
            $phone,
            "Now, please tell us which vehicle you use for deliveries.",
            [
                "motorcycle" => "🏍️ Motorcycle",
                "keke"  => "🛺 Keke"
            ]
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        // Check if message contains a comma (potential lat,lng format)
        if (strpos($message, ',') === false) {
            return false;
        }

        // Split the string into lat and lng
        $coordinates = explode(',', trim($message));

        // Validate we have exactly 2 parts
        if (count($coordinates) !== 2) {
            return false;
        }

        // Trim whitespace
        $latitude = trim($coordinates[0]);
        $longitude = trim($coordinates[1]);

        // Validate that both are numeric
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        // Convert to float for validation
        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        // Validate coordinate ranges
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return false;
        }

        return true;
    }
}