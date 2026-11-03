<?php

declare(strict_types=1);

namespace App\Steps\Shop;

use App\Steps\BaseStep;

class ShopCheckoutLocationStep extends BaseStep
{
    protected string $validationError = "📍 Share delivery location via WhatsApp (📎 → Location).";

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $parsed = $this->parseLocationPayload($message);
        if ($parsed === null) {
            return $this->validationError;
        }
        [$lat, $lng, $label] = $parsed;
        $d = $this->getSessionData();
        $d['checkout_delivery_lat'] = $lat;
        $d['checkout_delivery_lng'] = $lng;
        $d['checkout_delivery_address'] = $label ?: "({$lat}, {$lng})";
        $this->setSessionData($d);
        $this->nextStep($phone, 'shop_checkout_receiver');

        return "📍 Delivery: {$d['checkout_delivery_address']}\n\nWhat *phone number* should riders call at dropoff? (e.g. 08012345678)";
    }

    public function validateInput(string $message): bool
    {
        return $this->parseLocationPayload($message) !== null;
    }

    /**
     * @return array{0: float, 1: float, 2: string}|null
     */
    private function parseLocationPayload(string $message): ?array
    {
        $trim = trim($message);
        if ($trim === '' || $trim[0] !== '{') {
            return null;
        }
        $j = json_decode($trim, true);
        if (!is_array($j)) {
            return null;
        }
        $lat = $j['location']['latitude'] ?? $j['latitude'] ?? null;
        $lng = $j['location']['longitude'] ?? $j['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $name = (string) ($j['location']['name'] ?? $j['name'] ?? '');
        $addr = (string) ($j['location']['address'] ?? $j['address'] ?? '');
        $label = trim($name . ' ' . $addr);

        return [(float) $lat, (float) $lng, $label];
    }
}
