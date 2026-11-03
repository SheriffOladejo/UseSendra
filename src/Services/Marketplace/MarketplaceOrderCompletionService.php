<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceOrder;
use App\Models\StoreOrder;
use App\Services\WhatsAppService;

final class MarketplaceOrderCompletionService
{
    public function __construct(
        private readonly StoreOrder $storeOrderModel,
        private readonly MarketplaceOrder $orderModel,
        private readonly WhatsAppService $whatsapp,
        private readonly ?MarketplaceFailedDeliveryService $failedDelivery = null
    ) {
    }

    public function onDeliveryRequestDelivered(int $deliveryRequestId): void
    {
        $so = $this->storeOrderModel->findByDeliveryRequestId($deliveryRequestId);
        if (!$so) {
            $so = $this->storeOrderModel->findByReturnDeliveryRequestId($deliveryRequestId);
            if ($so && ($so['status'] ?? '') === 'returning' && $this->failedDelivery !== null) {
                $this->failedDelivery->completeReturn((int) $so['id']);
            }

            return;
        }
        if (in_array($so['status'] ?? '', ['delivered', 'cancelled', 'returned', 'failed'], true)) {
            return;
        }
        $this->storeOrderModel->update((int) $so['id'], ['status' => 'delivered']);
        $this->checkMarketplaceOrderComplete((int) $so['marketplace_order_id'], $so);
    }

    /**
     * @param array<string, mixed> $storeOrder
     */
    private function checkMarketplaceOrderComplete(int $marketplaceOrderId, array $storeOrder): void
    {
        $all = $this->storeOrderModel->listByMarketplaceOrder($marketplaceOrderId);
        $terminal = ['delivered', 'cancelled', 'returned', 'failed'];
        $done = true;
        foreach ($all as $row) {
            if (!in_array($row['status'] ?? '', $terminal, true)) {
                $done = false;
                break;
            }
        }
        if ($done) {
            $this->orderModel->update($marketplaceOrderId, ['status' => 'completed']);
        }
        $order = $this->orderModel->findById($marketplaceOrderId);
        if ($order) {
            $this->whatsapp->sendMessage(
                (string) $order['buyer_phone'],
                '✅ Delivered: ' . ($storeOrder['id'] ? '#SO-' . $storeOrder['id'] : 'store leg')
                . ' (order #M-' . $marketplaceOrderId . ').'
            );
        }
    }
}
