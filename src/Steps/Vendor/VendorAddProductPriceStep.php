<?php

declare(strict_types=1);

namespace App\Steps\Vendor;

use App\Models\Vendor;
use App\Models\VendorProduct;
use App\Steps\BaseStep;

class VendorAddProductPriceStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $price = (float) preg_replace('/[^0-9.]/', '', $message);
        if ($price <= 0) {
            return 'Enter a valid price (e.g. 1500).';
        }
        $vendor = (new Vendor($this->deliveryRequestModel->getDb()))->findByPhone($phone);
        if (!$vendor) {
            $this->nextStep($phone, 'vendor_store_name');

            return 'Set up your store first.';
        }
        $d = $this->getSessionData();
        $name = (string) ($d['new_product_name'] ?? 'Item');
        $pid = (new VendorProduct($this->deliveryRequestModel->getDb()))->create(
            (int) $vendor['id'],
            $name,
            $price
        );
        unset($d['new_product_name']);
        $this->setSessionData($d);
        $this->nextStep($phone, 'vendor_menu');

        return "✅ Listed *{$name}* at ₦" . number_format($price, 0) . " (id #{$pid}).\n\nBuyers can type *add_{$pid}*";
    }

    public function validateInput(string $message): bool
    {
        $price = (float) preg_replace('/[^0-9.]/', '', $message);

        return $price > 0;
    }
}
