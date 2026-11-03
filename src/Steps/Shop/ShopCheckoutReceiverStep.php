<?php

declare(strict_types=1);

namespace App\Steps\Shop;

use App\Services\Marketplace\MarketplaceWhatsAppHandler;
use App\Steps\BaseStep;

class ShopCheckoutReceiverStep extends BaseStep
{
    public function handle(array $session, string $message): string
    {
        $phone = $session['phone_number'];
        $receiver = preg_replace('/\D/', '', trim($message));
        if (strlen($receiver) < 10) {
            return 'Please enter a valid phone number (10+ digits).';
        }
        $d = $this->getSessionData();
        $d['checkout_receiver_phone'] = $receiver;
        $this->setSessionData($d);

        $handler = MarketplaceWhatsAppHandler::build(
            $this->deliveryRequestModel->getDb(),
            $this->whatsappService
        );
        $lat = (float) ($d['checkout_delivery_lat'] ?? 0);
        $lng = (float) ($d['checkout_delivery_lng'] ?? 0);
        $addr = (string) ($d['checkout_delivery_address'] ?? '');
        $summary = (new \App\Services\Marketplace\MarketplaceCartService(
            new \App\Models\MarketplaceCartItem($this->deliveryRequestModel->getDb()),
            new \App\Models\Vendor($this->deliveryRequestModel->getDb()),
            new \App\Models\VendorProduct($this->deliveryRequestModel->getDb()),
            \App\Services\DeliveryPricingService::fromEnv(),
            $this->driverModel,
            new \App\Services\Marketplace\VendorThrottleService(new \App\Models\Vendor($this->deliveryRequestModel->getDb()))
        ))->summarizeCart($phone, $lat, $lng);

        $result = $handler->checkout()->startCheckout($phone, $addr, $lat, $lng, $receiver);
        if (!($result['ok'] ?? false)) {
            $this->nextStep($phone, 'shop_menu');

            return '⚠️ ' . ($result['error'] ?? 'Checkout failed.');
        }

        $this->nextStep($phone, 'shop_menu');
        $this->whatsappService->sendMessage(
            $phone,
            "💳 *Pay ₦" . number_format($summary['total'], 0) . "* to complete your multi-store order:\n\n"
            . ($result['payment_url'] ?? '')
            . "\n\nAfter payment, each store must accept. You'll get updates here."
        );

        return '';
    }

    public function validateInput(string $message): bool
    {
        $digits = preg_replace('/\D/', '', trim($message));

        return strlen($digits) >= 10;
    }
}
