<?php

declare(strict_types=1);

namespace App\Steps\Vendor;

use App\Models\Vendor;
use App\Models\VendorProduct;
use App\Steps\BaseStep;

class VendorMenuStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $cmd = strtolower(trim($message));
        $vendor = (new Vendor($this->deliveryRequestModel->getDb()))->findByPhone($phone);
        if (!$vendor) {
            $this->nextStep($phone, 'vendor_store_name');

            return 'Please set up your store name first.';
        }
        $vid = (int) $vendor['id'];

        if ($cmd === 'add product' || $cmd === 'add_product') {
            $this->nextStep($phone, 'vendor_add_product_name');

            return 'Product *name*?';
        }
        if ($cmd === 'my products' || $cmd === 'my_products') {
            $products = (new VendorProduct($this->deliveryRequestModel->getDb()))->listByVendor($vid, false);
            if ($products === []) {
                return 'No products yet. Type *add product*.';
            }
            $lines = ["📋 *{$vendor['store_name']}* products\n"];
            foreach ($products as $p) {
                $lines[] = '#' . $p['id'] . ' ' . $p['name'] . ' — ₦' . number_format((float) $p['price_ngn'], 0)
                    . ' [' . $p['stock_status'] . ']';
            }

            return implode("\n", $lines);
        }
        if ($cmd === 'store open') {
            (new Vendor($this->deliveryRequestModel->getDb()))->update($vid, ['status' => 'active']);

            return '✅ Store is open for orders.';
        }
        if ($cmd === 'store closed') {
            (new Vendor($this->deliveryRequestModel->getDb()))->update($vid, ['status' => 'pending']);

            return '⏸️ Store marked closed (not visible in browse).';
        }

        return "Commands: *add product*, *my products*, *store open*, *store closed*";
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}
