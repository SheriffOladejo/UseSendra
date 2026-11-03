<?php

declare(strict_types=1);

namespace App\Steps\Vendor;

use App\Models\Vendor;
use App\Steps\BaseStep;

class VendorPickupLocationStep extends BaseStep
{
    protected string $validationError = '📍 Share pickup location via WhatsApp Location.';

    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $parsed = $this->parseLocationPayload($message);
        if ($parsed === null) {
            return $this->validationError;
        }
        [$lat, $lng, $label] = $parsed;
        $vendor = (new Vendor($this->deliveryRequestModel->getDb()))->findByPhone($phone);
        if ($vendor) {
            (new Vendor($this->deliveryRequestModel->getDb()))->update((int) $vendor['id'], [
                'pickup_lat' => $lat,
                'pickup_lng' => $lng,
                'pickup_address' => $label ?: "({$lat}, {$lng})",
                'status' => 'active',
            ]);
        }
        $this->nextStep($phone, 'vendor_menu');

        return "✅ Store is *active*.\n\nType *add product* to list items, or *my products* to view catalog.";
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

        return [(float) $lat, (float) $lng, trim($name . ' ' . $addr)];
    }
}
