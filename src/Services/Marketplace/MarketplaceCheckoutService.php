<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\LogisticsUser;
use App\Models\MarketplaceCartItem;
use App\Models\MarketplaceOrder;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Vendor;
use App\Services\CentiivDirectPayService;

final class MarketplaceCheckoutService
{
    private int $vendorAcceptMinutes;

    public function __construct(
        private readonly MarketplaceCartItem $cartModel,
        private readonly MarketplaceOrder $orderModel,
        private readonly StoreOrder $storeOrderModel,
        private readonly StoreOrderItem $storeOrderItemModel,
        private readonly LogisticsUser $userModel,
        private readonly MarketplaceCartService $cartService,
        private readonly CentiivDirectPayService $centiiv
    ) {
        $this->vendorAcceptMinutes = (int) (
            $_ENV['MARKETPLACE_VENDOR_ACCEPT_MINUTES'] ?? getenv('MARKETPLACE_VENDOR_ACCEPT_MINUTES') ?: '5'
        );
    }

    /**
     * @return array{ok:bool, order_id?:int, payment_url?:string, error?:string}
     */
    public function startCheckout(
        string $buyerPhone,
        string $deliveryAddress,
        float $deliveryLat,
        float $deliveryLng,
        string $receiverPhone
    ): array {
        $user = $this->userModel->findByPhone($buyerPhone);
        if (!$user) {
            $this->userModel->create($buyerPhone, null);
            $user = $this->userModel->findByPhone($buyerPhone);
        }
        $summary = $this->cartService->summarizeCart($buyerPhone, $deliveryLat, $deliveryLng);
        if ($summary['by_vendor'] === []) {
            return ['ok' => false, 'error' => 'Your cart is empty.'];
        }
        if ($summary['total'] <= 0) {
            return ['ok' => false, 'error' => 'Invalid order total.'];
        }

        $orderId = $this->orderModel->create([
            'buyer_user_id' => (int) ($user['id'] ?? 0),
            'buyer_phone' => $buyerPhone,
            'status' => 'payment_pending',
            'partial_fulfill_status' => 'none',
            'delivery_address' => $deliveryAddress,
            'delivery_lat' => $deliveryLat,
            'delivery_lng' => $deliveryLng,
            'receiver_phone' => $receiverPhone,
            'subtotal_ngn' => $summary['subtotal'],
            'delivery_total_ngn' => $summary['delivery_total'],
            'service_fee_ngn' => $summary['service_fee'],
            'total_ngn' => $summary['total'],
            'payment_status' => 'pending',
        ]);

        $serviceFee = (float) $summary['service_fee'];
        $subtotalAll = (float) $summary['subtotal'];
        $deadline = date('Y-m-d H:i:s', time() + $this->vendorAcceptMinutes * 60);

        foreach ($summary['by_vendor'] as $vid => $block) {
            $legFee = (float) ($summary['leg_fees'][$vid] ?? 0);
            $share = $subtotalAll > 0
                ? round($serviceFee * ((float) $block['subtotal'] / $subtotalAll), 2)
                : 0.0;
            $storeOrderId = $this->storeOrderModel->create([
                'marketplace_order_id' => $orderId,
                'vendor_id' => $vid,
                'status' => 'awaiting_vendor',
                'subtotal_ngn' => $block['subtotal'],
                'delivery_fee_ngn' => $legFee,
                'service_fee_share_ngn' => $share,
                'vendor_accept_deadline' => $deadline,
            ]);
            foreach ($block['items'] as $it) {
                $qty = (int) $it['quantity'];
                $unit = (float) $it['unit_price_ngn'];
                $this->storeOrderItemModel->create([
                    'store_order_id' => $storeOrderId,
                    'product_id' => (int) $it['product_id'],
                    'product_name' => (string) $it['product_name'],
                    'quantity' => $qty,
                    'unit_price_ngn' => $unit,
                    'line_total_ngn' => $unit * $qty,
                ]);
            }
        }

        $ref = 'MKT_PAY_' . $orderId . '_' . bin2hex(random_bytes(4));
        $this->orderModel->update($orderId, ['payment_reference' => $ref]);

        if (!$this->centiiv->isConfigured()) {
            return ['ok' => false, 'error' => 'Online payment is not configured. Please try later.'];
        }

        $link = $this->centiiv->createMarketplacePaymentLink(
            (float) $summary['total'],
            $ref,
            ['marketplace_order_id' => $orderId, 'type' => 'marketplace_checkout']
        );
        if ($link === null) {
            return ['ok' => false, 'error' => 'Could not create payment link. Try again.'];
        }
        $dpl = $this->centiiv->resolveDplIdAfterCreate($link);
        if ($dpl !== null) {
            $this->orderModel->update($orderId, ['centiiv_dpl_id' => $dpl]);
        }

        return [
            'ok' => true,
            'order_id' => $orderId,
            'payment_url' => $link['url'],
        ];
    }
}
