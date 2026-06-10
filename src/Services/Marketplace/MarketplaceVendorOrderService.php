<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceOrder;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Vendor;
use App\Services\WhatsAppService;

final class MarketplaceVendorOrderService
{
    public function __construct(
        private readonly StoreOrder $storeOrderModel,
        private readonly StoreOrderItem $storeOrderItemModel,
        private readonly MarketplaceOrder $orderModel,
        private readonly Vendor $vendorModel,
        private readonly VendorThrottleService $throttle,
        private readonly MarketplacePartialFulfillService $partialFulfill,
        private readonly MarketplaceDeliveryDispatchService $dispatch,
        private readonly WhatsAppService $whatsapp
    ) {
    }

    public function accept(int $storeOrderId, string $vendorPhone): array
    {
        $so = $this->storeOrderModel->findById($storeOrderId);
        if (!$so || ($so['status'] ?? '') !== 'awaiting_vendor') {
            return ['ok' => false, 'message' => 'Order not available to accept.'];
        }
        $vendor = $this->vendorModel->findByPhone($vendorPhone);
        if (!$vendor || (int) $vendor['id'] !== (int) $so['vendor_id']) {
            return ['ok' => false, 'message' => 'Not your order.'];
        }
        $this->storeOrderModel->update($storeOrderId, ['status' => 'accepted']);
        $this->throttle->recordVendorOrderAccepted((int) $vendor['id']);
        $this->whatsapp->sendButtonMessage(
            $vendorPhone,
            "✅ Order #SO-{$storeOrderId} accepted.\nWhen items are ready, tap *Ready*.",
            [
                'vendor_ready_' . $storeOrderId => '📦 Ready for pickup',
            ]
        );
        $order = $this->orderModel->findById((int) $so['marketplace_order_id']);
        if ($order) {
            $this->whatsapp->sendMessage(
                (string) $order['buyer_phone'],
                '✅ ' . ($vendor['store_name'] ?? 'A store') . " accepted your order (leg #SO-{$storeOrderId})."
            );
        }

        return ['ok' => true, 'message' => ''];
    }

    public function reject(int $storeOrderId, string $vendorPhone): array
    {
        $so = $this->storeOrderModel->findById($storeOrderId);
        if (!$so || ($so['status'] ?? '') !== 'awaiting_vendor') {
            return ['ok' => false, 'message' => 'Order not available.'];
        }
        $vendor = $this->vendorModel->findByPhone($vendorPhone);
        if (!$vendor || (int) $vendor['id'] !== (int) $so['vendor_id']) {
            return ['ok' => false, 'message' => 'Not your order.'];
        }
        $this->storeOrderModel->update($storeOrderId, [
            'status' => 'cancelled',
            'cancel_reason' => 'vendor_reject',
        ]);
        $this->throttle->recordVendorCancel((int) $vendor['id'], 'vendor_reject');
        $this->partialFulfill->maybePromptBuyer((int) $so['marketplace_order_id']);

        return ['ok' => true, 'message' => 'Order declined. Buyer will be notified.'];
    }

    public function markReady(int $storeOrderId, string $vendorPhone): array
    {
        $so = $this->storeOrderModel->findById($storeOrderId);
        if (!$so || !in_array($so['status'] ?? '', ['accepted', 'preparing'], true)) {
            return ['ok' => false, 'message' => 'Order must be accepted first.'];
        }
        $vendor = $this->vendorModel->findByPhone($vendorPhone);
        if (!$vendor || (int) $vendor['id'] !== (int) $so['vendor_id']) {
            return ['ok' => false, 'message' => 'Not your order.'];
        }
        $this->storeOrderModel->update($storeOrderId, ['status' => 'ready']);
        $drId = $this->dispatch->createDeliveryJobForStoreOrder($storeOrderId);
        if ($drId <= 0) {
            return ['ok' => false, 'message' => 'Could not start delivery. Check store pickup location.'];
        }
        $this->storeOrderModel->update($storeOrderId, [
            'status' => 'rider_assigned',
            'delivery_request_id' => $drId,
        ]);
        $order = $this->orderModel->findById((int) $so['marketplace_order_id']);
        if ($order) {
            $this->whatsapp->sendMessage(
                (string) $order['buyer_phone'],
                '🚚 Rider search started for ' . ($vendor['store_name'] ?? 'your items') . " (#SO-{$storeOrderId})."
            );
        }

        return ['ok' => true, 'message' => 'Riders notified for pickup.'];
    }
}
