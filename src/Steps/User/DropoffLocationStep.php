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

        $parsed = $this->parseLocationPayload($message);
        if ($parsed === null) {
            return $this->validationError;
        }
        [$latitude, $longitude, $label] = $parsed;

        // Update the delivery request with dropoff location
        $this->deliveryRequestModel->update($requestId, [
            'dropoff_lat' => $latitude,
            'dropoff_lng' => $longitude,
            // Keep a driver-friendly text representation even if we removed typed-address step
            'dropoff_address' => $label ?: ('(' . $latitude . ', ' . $longitude . ')'),
        ]);

        if ($this->isEditMode() && $this->getEditTarget() === 'edit_dropoff_location') {
            // Changing coordinates impacts pricing; send user to vehicle selection to refresh estimate.
            $this->deliveryRequestModel->update($requestId, ['offered_price' => null]);
            $this->nextStep($phone, 'sender_vehicle_type');
            $this->whatsappService->sendButtonMessage(
                $phone,
                "Drop-off updated. To refresh pricing, please re-select the delivery vehicle type:",
                [
                    'motorcycle' => '🏍 Bike',
                    'keke' => '🛺 Keke',
                ]
            );
            return '';
        }

        // Move to the next step (e.g., item details)
        $this->nextStep($phone, 'dropoff_notes');

        $this->whatsappService->sendMessage(
            $phone,
            "Got it! Please give more context or specific instructions for the driver."
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        return $this->parseLocationPayload($message) !== null;
    }

    /**
     * @return array{0:float,1:float,2:string}|null
     */
    private function parseLocationPayload(string $message): ?array
    {
        $message = trim($message);

        // JSON location payload from webhook
        if ($message !== '' && $message[0] === '{') {
            $j = json_decode($message, true);
            if (is_array($j) && isset($j['location'])) {
                $loc = $j['location'];
                $lat = $loc['latitude'] ?? null;
                $lng = $loc['longitude'] ?? null;
                if (is_numeric($lat) && is_numeric($lng)) {
                    $latF = (float) $lat;
                    $lngF = (float) $lng;
                    if ($latF >= -90 && $latF <= 90 && $lngF >= -180 && $lngF <= 180) {
                        $address = trim((string) ($loc['address'] ?? ''));
                        $name = trim((string) ($loc['name'] ?? ''));
                        $label = $address !== '' ? $address : $name;
                        return [$latF, $lngF, $label];
                    }
                }
            }
        }

        // Legacy: lat,lng string
        if (strpos($message, ',') === false) {
            return null;
        }
        $coordinates = explode(',', $message, 2);
        if (count($coordinates) !== 2) {
            return null;
        }
        $lat = trim($coordinates[0]);
        $lng = trim($coordinates[1]);
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
            return null;
        }
        return [$latF, $lngF, ''];
    }
}