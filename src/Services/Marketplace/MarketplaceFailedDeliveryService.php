<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\DeliveryRequest;
use App\Models\MarketplaceOrder;
use App\Models\StoreOrder;
use App\Models\Vendor;
use App\Models\VendorWalletTransaction;
use App\Services\WhatsAppService;

/**
 * Failed delivery: only when receiver is unavailable to receive goods.
 * Return fee = leg delivery fee; vendor wallet may go negative.
 */
final class MarketplaceFailedDeliveryService
{
    public function __construct(
        private readonly DeliveryRequest $deliveryRequestModel,
        private readonly StoreOrder $storeOrderModel,
        private readonly MarketplaceOrder $orderModel,
        private readonly Vendor $vendorModel,
        private readonly VendorWalletTransaction $walletTxModel,
        private readonly MarketplaceDeliveryDispatchService $dispatch,
        private readonly MarketplaceRefundNotifier $refundNotifier,
        private readonly MarketplacePartialFulfillService $partialFulfill,
        private readonly WhatsAppService $whatsapp
    ) {
    }

    public function markReceiverUnavailable(int $deliveryRequestId, string $riderPhone): array
    {
        $req = $this->deliveryRequestModel->findById($deliveryRequestId);
        if (!$req || empty($req['store_order_id'])) {
            return ['ok' => false, 'message' => 'Not a marketplace delivery job.'];
        }
        if (!in_array($req['status'] ?? '', ['in_transit', 'driver_assigned'], true)) {
            return ['ok' => false, 'message' => 'Job is not in delivery.'];
        }

        $storeOrderId = (int) $req['store_order_id'];
        $so = $this->storeOrderModel->findById($storeOrderId);
        if (!$so) {
            return ['ok' => false, 'message' => 'Store order missing.'];
        }

        $returnFee = (float) ($so['delivery_fee_ngn'] ?? 0);
        $this->storeOrderModel->update($storeOrderId, [
            'status' => 'failed',
            'cancel_reason' => 'receiver_unavailable',
        ]);
        $this->deliveryRequestModel->update($deliveryRequestId, ['status' => 'cancelled']);

        $vendorId = (int) $so['vendor_id'];
        $ref = 'VRET_FEE_SO' . $storeOrderId . '_' . time();
        if (!$this->walletTxModel->referenceExists($ref)) {
            $newBal = $this->vendorModel->adjustWallet($vendorId, -$returnFee);
            $this->walletTxModel->insert(
                $vendorId,
                'return_fee',
                -$returnFee,
                $newBal,
                $ref,
                $storeOrderId,
                ['reason' => 'receiver_unavailable']
            );
            $this->storeOrderModel->update($storeOrderId, [
                'return_fee_charged_ngn' => $returnFee,
                'status' => 'returning',
            ]);
            $returnDrId = $this->dispatch->createReturnJobForStoreOrder($storeOrderId);
            if ($returnDrId > 0) {
                $this->storeOrderModel->update($storeOrderId, ['return_delivery_request_id' => $returnDrId]);
            }
            $vendor = $this->vendorModel->findById($vendorId);
            if ($vendor) {
                $this->whatsapp->sendMessage(
                    (string) $vendor['phone'],
                    "⚠️ Delivery failed — receiver unavailable (#SO-{$storeOrderId}).\n"
                    . 'Return fee ₦' . number_format($returnFee, 0) . " charged (same as delivery).\n"
                    . 'Wallet balance: ₦' . number_format($newBal, 0)
                );
            }
        }

        $order = $this->orderModel->findById((int) $so['marketplace_order_id']);
        if ($order) {
            $refund = (float) $so['subtotal_ngn'] + (float) $so['delivery_fee_ngn'] + (float) $so['service_fee_share_ngn'];
            $this->refundNotifier->recordAndNotifyPartialRefund($order, $refund, 'receiver_unavailable');
            $this->whatsapp->sendMessage(
                (string) $order['buyer_phone'],
                "📦 We couldn't reach the receiver for #SO-{$storeOrderId}. "
                . '₦' . number_format($refund, 0) . ' refund is being processed for that store leg.'
            );
        }

        return ['ok' => true, 'message' => 'Marked receiver unavailable. Return initiated; vendor charged return fee.'];
    }

    public function completeReturn(int $storeOrderId): void
    {
        $so = $this->storeOrderModel->findById($storeOrderId);
        if (!$so || ($so['status'] ?? '') !== 'returning') {
            return;
        }
        $this->storeOrderModel->update($storeOrderId, ['status' => 'returned']);
    }
}
