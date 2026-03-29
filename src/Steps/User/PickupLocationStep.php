<?php

namespace App\Steps\User;

use App\Steps\BaseStep;

class PickupLocationStep extends BaseStep
{
    protected string $validationError = "📍 We couldn't get your pickup location.\n\n" .
        "Please share your location using WhatsApp:\n" .
        "1. Tap the '+' icon\n" .
        "2. Select 'Location'\n" .
        "3. Send your current location or pick a spot on the map";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];

        // Try to parse coordinates from message
        $coordinates = $this->parseCoordinates($message);

        if (!$coordinates) {
            return $this->validationError;
        }

        [$latitude, $longitude] = $coordinates;

        $user = $this->userModel->findByPhone($phone);

        $requestId = $this->deliveryRequestModel->create([
            'user_id' => $user['id'],
            'sender_phone' => $phone,
            'pickup_lat'  => $latitude,
            'pickup_lng' => $longitude
        ]);

        $this->sessionModel->update($phone, [
            'delivery_request_id' => $requestId
        ]);

        // Move to next step
        $this->nextStep($phone, 'pickup_notes');

        $this->whatsappService->sendMessage(
            $phone,
            "Got it! Please give more context on the details of the pickup location.\n\nFor example, you can mention landmarks, building names, or any specific instructions for the driver."
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return $this->parseCoordinates($message) !== false;
    }

    /**
     * Extract latitude and longitude from user input.
     * Accepts "lat,lng" or WhatsApp location format.
     */
    private function parseCoordinates(string $message)
    {
        $message = trim($message);

        // If message contains a comma, assume lat,lng
        if (strpos($message, ',') !== false) {
            $parts = explode(',', $message);
            if (count($parts) === 2) {
                $lat = (float) trim($parts[0]);
                $lng = (float) trim($parts[1]);

                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    return [$lat, $lng];
                }
            }
        }

        // TODO: add WhatsApp location payload parsing if you get location objects
        // e.g., $message might be JSON or WhatsApp Location object

        return false;
    }
}