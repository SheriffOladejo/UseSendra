<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceOrder;
use App\Models\Session;
use App\Models\StoreOrder;
use App\Models\Vendor;
use App\Services\WhatsAppService;

/**
 * Option A: buyer continues without cancelled store(s) or cancels entire order.
 */
final class MarketplacePartialFulfillService
{
    public function __construct(
        private readonly MarketplaceOrder $orderModel,
        private readonly StoreOrder $storeOrderModel,
        private readonly Vendor $vendorModel,
        private readonly MarketplaceRefundNotifier $refundNotifier,
        private readonly VendorThrottleService $throttle,
        private readonly ?Session $sessionModel = null
    ) {
    }

    public function cancelStoreOrder(int $storeOrderId, string $reason): void
    {
        $so = $this->storeOrderModel->findById($storeOrderId);
        if (!$so || ($so['status'] ?? '') !== 'awaiting_vendor') {
            return;
        }
        $this->storeOrderModel->update($storeOrderId, [
            'status' => 'cancelled',
            'cancel_reason' => $reason,
        ]);
        $this->throttle->recordVendorCancel((int) $so['vendor_id'], $reason);
        $this->maybePromptBuyer((int) $so['marketplace_order_id']);
    }

    public function maybePromptBuyer(int $marketplaceOrderId): void
    {
        $order = $this->orderModel->findById($marketplaceOrderId);
        if (!$order || ($order['partial_fulfill_status'] ?? 'none') !== 'none') {
            return;
        }
        $storeOrders = $this->storeOrderModel->listByMarketplaceOrder($marketplaceOrderId);
        $cancelled = [];
        $active = [];
        foreach ($storeOrders as $so) {
            if (($so['status'] ?? '') === 'cancelled') {
                $cancelled[] = $so;
            } elseif (!in_array($so['status'] ?? '', ['delivered', 'returned', 'failed'], true)) {
                $active[] = $so;
            }
        }
        if ($cancelled === []) {
            return;
        }
        if ($active === []) {
            $this->promptCancelAllOnly($order, $cancelled);

            return;
        }
        $this->orderModel->update($marketplaceOrderId, [
            'partial_fulfill_status' => 'pending',
            'status' => 'partially_fulfilled',
        ]);
        $refundIfContinue = $this->refundAmountForStoreOrders($cancelled);
        $fullRefund = (float) ($order['total_ngn'] ?? 0) - (float) ($order['refund_total_ngn'] ?? 0);

        $lines = ["📦 *Order #M-{$marketplaceOrderId}* — update\n"];
        foreach ($cancelled as $so) {
            $v = $this->vendorModel->findById((int) $so['vendor_id']);
            $name = $v['store_name'] ?? 'A store';
            $lines[] = "❌ *{$name}* could not fulfil this order.";
        }
        $lines[] = "\nRefund if you *continue*: ₦" . number_format($refundIfContinue, 0);
        $lines[] = "\nStill active:";
        foreach ($active as $so) {
            $v = $this->vendorModel->findById((int) $so['vendor_id']);
            $lines[] = '✅ ' . ($v['store_name'] ?? 'Store') . ' — ' . $this->statusLabel($so['status'] ?? '');
        }
        $lines[] = "\nReply:\n• *continue* — order without cancelled store(s)\n• *cancel all* — full refund ₦" . number_format($fullRefund, 0);

        $buyerPhone = (string) $order['buyer_phone'];
        $this->refundNotifier->sendToBuyer($buyerPhone, implode("\n", $lines));
        $this->armBuyerPartialFulfillStep($buyerPhone, $marketplaceOrderId);
    }

    private function armBuyerPartialFulfillStep(string $buyerPhone, int $marketplaceOrderId): void
    {
        if ($this->sessionModel === null) {
            return;
        }
        $sess = $this->sessionModel->findByPhone($buyerPhone);
        $raw = is_array($sess) ? ($sess['data'] ?? $sess['session_data'] ?? '{}') : '{}';
        $d = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $d['pending_marketplace_order_id'] = $marketplaceOrderId;
        $json = json_encode($d, JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->sessionModel->update($buyerPhone, ['data' => $json, 'current_step' => 'marketplace_partial_fulfill']);
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public function handleBuyerChoice(int $marketplaceOrderId, string $choice): array
    {
        $order = $this->orderModel->findById($marketplaceOrderId);
        if (!$order) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }
        if (($order['partial_fulfill_status'] ?? '') !== 'pending') {
            return ['ok' => false, 'message' => 'No pending choice for this order.'];
        }
        $choice = strtolower(trim($choice));
        if ($choice === 'continue' || $choice === 'continued') {
            return $this->applyContinue($order);
        }
        if ($choice === 'cancel all' || $choice === 'cancel_all' || $choice === 'cancelall') {
            return $this->applyCancelAll($order);
        }

        return ['ok' => false, 'message' => 'Reply *continue* or *cancel all*.'];
    }

    /**
     * @param array<string, mixed> $order
     * @return array{ok:bool, message:string}
     */
    private function applyContinue(array $order): array
    {
        $mid = (int) $order['id'];
        $storeOrders = $this->storeOrderModel->listByMarketplaceOrder($mid);
        $refund = 0.0;
        foreach ($storeOrders as $so) {
            if (($so['status'] ?? '') === 'cancelled') {
                $refund += $this->refundAmountForStoreOrder($so);
            }
        }
        if ($refund > 0) {
            $this->refundNotifier->recordAndNotifyPartialRefund($order, $refund, 'partial_continue');
        }
        $this->orderModel->update($mid, ['partial_fulfill_status' => 'continued']);

        return [
            'ok' => true,
            'message' => "✅ Continuing your order without cancelled store(s).\n"
                . ($refund > 0 ? "₦" . number_format($refund, 0) . " refund is being processed.\n" : '')
                . "Type *orders* to track.",
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array{ok:bool, message:string}
     */
    private function applyCancelAll(array $order): array
    {
        $mid = (int) $order['id'];
        $remaining = (float) ($order['total_ngn'] ?? 0) - (float) ($order['refund_total_ngn'] ?? 0);
        foreach ($this->storeOrderModel->listByMarketplaceOrder($mid) as $so) {
            if (!in_array($so['status'] ?? '', ['delivered', 'cancelled', 'returned'], true)) {
                $this->storeOrderModel->update((int) $so['id'], [
                    'status' => 'cancelled',
                    'cancel_reason' => 'buyer_cancel_all',
                ]);
            }
        }
        $this->refundNotifier->recordAndNotifyPartialRefund($order, $remaining, 'cancel_all');
        $this->orderModel->update($mid, [
            'partial_fulfill_status' => 'cancelled_all',
            'status' => 'cancelled',
        ]);

        return [
            'ok' => true,
            'message' => '✅ Order cancelled. Full refund of ₦' . number_format($remaining, 0) . ' is being processed.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $cancelled
     */
    private function promptCancelAllOnly(array $order, array $cancelled): void
    {
        $mid = (int) $order['id'];
        $this->orderModel->update($mid, ['partial_fulfill_status' => 'pending']);
        $remaining = (float) ($order['total_ngn'] ?? 0) - (float) ($order['refund_total_ngn'] ?? 0);
        $buyerPhone = (string) $order['buyer_phone'];
        $this->refundNotifier->sendToBuyer(
            $buyerPhone,
            "📦 *Order #M-{$mid}* — all stores declined.\n\nReply *cancel all* for a full refund (₦" . number_format($remaining, 0) . ")."
        );
        $this->armBuyerPartialFulfillStep($buyerPhone, $mid);
    }

    /**
     * @param list<array<string, mixed>> $storeOrders
     */
    private function refundAmountForStoreOrders(array $storeOrders): float
    {
        $t = 0.0;
        foreach ($storeOrders as $so) {
            $t += $this->refundAmountForStoreOrder($so);
        }

        return $t;
    }

    /**
     * @param array<string, mixed> $so
     */
    private function refundAmountForStoreOrder(array $so): float
    {
        return (float) $so['subtotal_ngn']
            + (float) $so['delivery_fee_ngn']
            + (float) $so['service_fee_share_ngn'];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'awaiting_vendor' => 'waiting for vendor',
            'accepted', 'preparing' => 'preparing',
            'ready', 'rider_assigned', 'picked_up' => 'on the way',
            default => $status,
        };
    }
}
