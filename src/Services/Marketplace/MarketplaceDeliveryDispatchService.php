<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Jobs\FindNearbyCouriersJob;
use App\Models\DeliveryRequest;
use App\Models\LogisticsDriver;
use App\Models\MarketplaceOrder;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Vendor;
use App\Services\WhatsAppService;

final class MarketplaceDeliveryDispatchService
{
    public function __construct(
        private readonly DeliveryRequest $deliveryRequestModel,
        private readonly MarketplaceOrder $orderModel,
        private readonly StoreOrder $storeOrderModel,
        private readonly StoreOrderItem $storeOrderItemModel,
        private readonly Vendor $vendorModel,
        private readonly LogisticsDriver $driverModel,
        private readonly WhatsAppService $whatsapp
    ) {
    }

    public function createDeliveryJobForStoreOrder(int $storeOrderId): int
    {
        $so = $this->storeOrderModel->findById($storeOrderId);
        if (!$so) {
            return 0;
        }
        $order = $this->orderModel->findById((int) $so['marketplace_order_id']);
        $vendor = $this->vendorModel->findById((int) $so['vendor_id']);
        if (!$order || !$vendor) {
            return 0;
        }
        $items = $this->storeOrderItemModel->listByStoreOrder($storeOrderId);
        $names = array_map(static fn ($i) => $i['product_name'] . '×' . $i['quantity'], $items);
        $itemSummary = $names !== [] ? implode(', ', $names) : 'Marketplace order';

        $requestId = $this->deliveryRequestModel->create([
            'user_id' => (int) ($order['buyer_user_id'] ?? 0),
            'marketplace_order_id' => (int) $order['id'],
            'store_order_id' => $storeOrderId,
            'pickup_address' => (string) ($vendor['pickup_address'] ?? $vendor['store_name'] ?? 'Vendor pickup'),
            'pickup_lat' => $vendor['pickup_lat'] ?? null,
            'pickup_lng' => $vendor['pickup_lng'] ?? null,
            'dropoff_address' => (string) ($order['delivery_address'] ?? ''),
            'dropoff_lat' => $order['delivery_lat'] ?? null,
            'dropoff_lng' => $order['delivery_lng'] ?? null,
            'item_name' => 'Shop: ' . ($vendor['store_name'] ?? 'Store'),
            'item_description' => $itemSummary,
            'sender_phone' => (string) ($order['buyer_phone'] ?? ''),
            'receiver_phone' => (string) ($order['receiver_phone'] ?? $order['buyer_phone'] ?? ''),
            'vehicle_type' => 'bike',
            'offered_price' => (float) ($so['delivery_fee_ngn'] ?? 0),
            'payer' => 'sender',
            'payment_method' => 'paid_online',
        ]);

        if (!$requestId) {
            return 0;
        }

        $this->deliveryRequestModel->update((int) $requestId, ['status' => 'searching_driver']);

        $job = new FindNearbyCouriersJob(
            (int) $requestId,
            $this->driverModel,
            $this->deliveryRequestModel,
            $this->whatsapp
        );
        $job->handle();

        return (int) $requestId;
    }

    public function createReturnJobForStoreOrder(int $storeOrderId): int
    {
        $so = $this->storeOrderModel->findById($storeOrderId);
        if (!$so) {
            return 0;
        }
        $order = $this->orderModel->findById((int) $so['marketplace_order_id']);
        $vendor = $this->vendorModel->findById((int) $so['vendor_id']);
        if (!$order || !$vendor) {
            return 0;
        }

        $requestId = $this->deliveryRequestModel->create([
            'user_id' => (int) ($order['buyer_user_id'] ?? 0),
            'marketplace_order_id' => (int) $order['id'],
            'store_order_id' => $storeOrderId,
            'pickup_address' => (string) ($order['delivery_address'] ?? 'Buyer location'),
            'pickup_lat' => $order['delivery_lat'] ?? null,
            'pickup_lng' => $order['delivery_lng'] ?? null,
            'dropoff_address' => (string) ($vendor['pickup_address'] ?? $vendor['store_name'] ?? 'Vendor'),
            'dropoff_lat' => $vendor['pickup_lat'] ?? null,
            'dropoff_lng' => $vendor['pickup_lng'] ?? null,
            'item_name' => 'Return: ' . ($vendor['store_name'] ?? ''),
            'item_description' => 'Return to vendor — receiver unavailable',
            'sender_phone' => (string) ($order['buyer_phone'] ?? ''),
            'receiver_phone' => (string) ($vendor['phone'] ?? ''),
            'vehicle_type' => 'bike',
            'offered_price' => (float) ($so['delivery_fee_ngn'] ?? 0),
            'payer' => 'sender',
            'payment_method' => 'paid_online',
        ]);
        if ($requestId) {
            $this->deliveryRequestModel->update((int) $requestId, ['status' => 'searching_driver']);
            $job = new FindNearbyCouriersJob(
                (int) $requestId,
                $this->driverModel,
                $this->deliveryRequestModel,
                $this->whatsapp
            );
            $job->handle();
        }

        return (int) $requestId;
    }
}
