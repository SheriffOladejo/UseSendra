<?php

declare(strict_types=1);

namespace App\Steps\Vendor;

use App\Steps\BaseStep;

class VendorAddProductNameStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $name = trim($message);
        if ($name === '') {
            return 'Enter a product name.';
        }
        $d = $this->getSessionData();
        $d['new_product_name'] = $name;
        $this->setSessionData($d);
        $this->nextStep($phone, 'vendor_add_product_price');

        return 'Price in *NGN*? (numbers only, e.g. 2500)';
    }

    public function validateInput(string $message): bool
    {
        return trim($message) !== '';
    }
}
