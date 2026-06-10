<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceCartItem;
use App\Models\MarketplaceOrder;
use App\Models\StoreOrder;
use App\Models\Vendor;
use App\Services\WhatsAppService;

final class MarketplaceAfterPaymentService
{
    public function __construct(
        private readonly MarketplaceOrder $orderModel,
        private readonly StoreOrder $storeOrderModel,
        private readonly MarketplaceCartItem $cartModel,
        private readonly Vendor $vendorModel,
        private readonly WhatsAppService $whatsapp
    ) {
    }

    public function handlePaid(int $marketplaceOrderId, float $amountPaidNgn, string $reference): bool
    {
        $order = $this->orderModel->findById($marketplaceOrderId);
        if (!$order) {
            return false;
        }
        if (($order['payment_status'] ?? '') === 'paid') {
            return true;
        }

        $this->orderModel->update($marketplaceOrderId, [
            'status' => 'paid',
            'payment_status' => 'paid',
            'amount_paid_ngn' => $amountPaidNgn,
            'payment_reference' => $reference,
        ]);

        $this->cartModel->clear((string) $order['buyer_phone']);

        $storeOrders = $this->storeOrderModel->listByMarketplaceOrder($marketplaceOrderId);
        foreach ($storeOrders as $so) {
            if (($so['status'] ?? '') !== 'awaiting_vendor') {
                continue;
            }
            $vendor = $this->vendorModel->findById((int) $so['vendor_id']);
            if (!$vendor) {
                continue;
            }
            $this->notifyVendorNewOrder($vendor, $so, $order);
        }

        $this->whatsapp->sendMessage(
            (string) $order['buyer_phone'],
            "✅ Payment received for order *#M-{$marketplaceOrderId}* (₦" . number_format($amountPaidNgn, 0) . ").\n"
            . "We're waiting for each store to accept. You'll get updates here.\n\nType *orders* to track."
        );

        return true;
    }

    /**
     * @param array<string, mixed> $vendor
     * @param array<string, mixed> $storeOrder
     * @param array<string, mixed> $marketplaceOrder
     */
    private function notifyVendorNewOrder(array $vendor, array $storeOrder, array $marketplaceOrder): void
    {
        $soId = (int) $storeOrder['id'];
        $this->whatsapp->sendButtonMessage(
            (string) $vendor['phone'],
            "🛍️ *New order* #SO-{$soId}\n"
            . 'Store: ' . ($vendor['store_name'] ?? '') . "\n"
            . 'Items total: ₦' . number_format((float) $storeOrder['subtotal_ngn'], 0) . "\n"
            . "Accept within a few minutes.",
            [
                'vendor_accept_' . $soId => '✅ Accept',
                'vendor_reject_' . $soId => '❌ Reject',
            ]
        );
    }
}
