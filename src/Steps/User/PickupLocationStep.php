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
        $requestId = $session['delivery_request_id'] ?? null;
        if (!$requestId) {
            // Defensive: create a draft request if we somehow got here without one.
            $user = $this->userModel->findByPhone($phone);
            if ($user) {
                $newId = $this->deliveryRequestModel->create([
                    'user_id' => $user['id'],
                    'sender_phone' => $phone,
                ]);
                if ($newId) {
                    $requestId = $newId;
                    $this->sessionModel->update($phone, ['delivery_request_id' => $requestId]);
                }
            }
        }
        if (!$requestId) {
            return "We couldn't find your delivery request. Please start again.";
        }

        // Try to parse coordinates (and optional WhatsApp location name/address)
        $parsed = $this->parseLocationPayload($message);

        if ($parsed === null) {
            return $this->validationError;
        }

        [$latitude, $longitude, $label] = $parsed;

        $this->deliveryRequestModel->update($requestId, [
            'pickup_lat'  => $latitude,
            'pickup_lng' => $longitude,
            // Keep a driver-friendly text representation even if we removed typed-address step
            'pickup_address' => $label ?: ('(' . $latitude . ', ' . $longitude . ')'),
        ]);

        if ($this->isEditMode() && $this->getEditTarget() === 'edit_pickup_location') {
            // Changing coordinates impacts pricing; send user to vehicle selection to refresh estimate.
            $this->deliveryRequestModel->update($requestId, ['offered_price' => null]);
            $this->nextStep($phone, 'sender_vehicle_type');
            $this->whatsappService->sendListMessage(
                $phone,
                "Pickup updated. To refresh pricing, please re-select the delivery vehicle type:",
                'Choose vehicle',
                \App\Utils\VehicleTypeHelper::customerListRows()
            );
            return '';
        }

        // Move to next step
        $this->nextStep($phone, 'dropoff_location');

        return "Got it.\n\n"
            . "Now, where’s it going?\n\n"
            . "Please share the drop-off location using WhatsApp's location sharing feature:\n"
            . "1. Tap the '➕' icon (iOS) or '📎' (Android)\n"
            . "2. Select 'Location'\n"
            . "3. Search on the map or send your current location.";
    }

    public function validateInput(string $message): bool
    {
        return $this->parseLocationPayload($message) !== null;
    }

    /**
     * Extract latitude/longitude and a human label from input.
     *
     * Accepts:
     * - Legacy: "lat,lng"
     * - Webhook JSON: {"location":{"latitude":..,"longitude":..,"name":..,"address":..}}
     *
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

        // If message contains a comma, assume lat,lng
        if (strpos($message, ',') !== false) {
            $parts = explode(',', $message);
            if (count($parts) === 2) {
                $lat = (float) trim($parts[0]);
                $lng = (float) trim($parts[1]);

                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    return [$lat, $lng, ''];
                }
            }
        }

        return null;
    }
}