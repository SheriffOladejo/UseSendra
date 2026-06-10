<?php

declare(strict_types=1);

namespace App\Steps\Vendor;

use App\Models\Vendor;
use App\Steps\BaseStep;

class VendorStoreNameStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $name = trim($message);
        if ($name === '' || strlen($name) < 2) {
            return 'Please enter a store name (2+ characters).';
        }
        $vendorModel = new Vendor($this->deliveryRequestModel->getDb());
        $existing = $vendorModel->findByPhone($phone);
        if (!$existing) {
            $vendorModel->create($phone, $name);
        } else {
            $vendorModel->update((int) $existing['id'], ['store_name' => $name]);
        }
        $this->nextStep($phone, 'vendor_pickup_location');

        return "🏪 *{$name}*\n\nShare your *pickup location* (where riders collect orders):\n📎 → Location";
    }

    public function validateInput(string $message): bool
    {
        return strlen(trim($message)) >= 2;
    }
}
