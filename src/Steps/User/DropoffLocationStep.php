<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class DropoffLocationStep extends BaseStep
{
    protected string $validationError = "📍 We couldn't get your drop-off location. 
Please use WhatsApp's location sharing feature:
1. Tap the '+' icon
2. Select 'Location'
3. Choose 'Send your current location' or pick a spot on the map";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];

        // Validate the delivery request exists
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) {
            return "We couldn't find your delivery request. Please start again.";
        }

        // Validate the location input
        $coordinates = explode(',', trim($message));
        if (count($coordinates) !== 2 || !is_numeric(trim($coordinates[0])) || !is_numeric(trim($coordinates[1]))) {
            return $this->validationError;
        }

        $latitude = (float) trim($coordinates[0]);
        $longitude = (float) trim($coordinates[1]);

        // Update the delivery request with dropoff location
        $this->deliveryRequestModel->update($requestId, [
            'dropoff_lat' => $latitude,
            'dropoff_lng' => $longitude
        ]);

        // Move to the next step (e.g., item details)
        $this->nextStep($phone, 'dropoff_notes');

        $this->whatsappService->sendMessage(
            $phone,
            "Got it! Please give more context on the details of the drop-off location.\n\nFor example, you can mention landmarks, building names, or any specific instructions for the driver."
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        $coordinates = explode(',', trim($message));
        if (count($coordinates) !== 2) return false;

        $lat = trim($coordinates[0]);
        $lng = trim($coordinates[1]);

        return is_numeric($lat) && is_numeric($lng) &&
               $lat >= -90 && $lat <= 90 &&
               $lng >= -180 && $lng <= 180;
    }
}