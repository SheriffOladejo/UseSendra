<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceCartItem;
use App\Models\Vendor;
use App\Models\VendorProduct;
use App\Services\DeliveryPricingService;
use App\Models\LogisticsDriver;

/**
 * Multi-store cart: grouped by vendor with per-leg delivery fee estimates.
 */
final class MarketplaceCartService
{
    public function __construct(
        private readonly MarketplaceCartItem $cartModel,
        private readonly Vendor $vendorModel,
        private readonly VendorProduct $productModel,
        private readonly DeliveryPricingService $pricing,
        private readonly LogisticsDriver $driverModel,
        private readonly VendorThrottleService $throttle
    ) {
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function addProduct(string $buyerPhone, int $productId, int $quantity = 1): array
    {
        $product = $this->productModel->findById($productId);
        if (!$product || ($product['status'] ?? '') !== 'active' || ($product['stock_status'] ?? '') !== 'in_stock') {
            return ['ok' => false, 'error' => 'Product not available.'];
        }
        $vendorId = (int) $product['vendor_id'];
        if (!$this->throttle->isVendorBrowsable($vendorId)) {
            return ['ok' => false, 'error' => 'This store is not accepting orders right now.'];
        }
        $qty = max(1, min(99, $quantity));
        $this->cartModel->addOrUpdate(
            $buyerPhone,
            $vendorId,
            $productId,
            (string) $product['name'],
            (float) $product['price_ngn'],
            $qty
        );

        return ['ok' => true];
    }

    /**
     * @return array{
     *   items: list<array<string,mixed>>,
     *   by_vendor: array<int, array{vendor_id:int,store_name:string,subtotal:float,items:list}>,
     *   subtotal: float,
     *   delivery_total: float,
     *   service_fee: float,
     *   total: float,
     *   leg_fees: array<int, float>
     * }
     */
    public function summarizeCart(string $buyerPhone, ?float $dropLat = null, ?float $dropLng = null): array
    {
        $items = $this->cartModel->listByBuyer($buyerPhone);
        $byVendor = [];
        $subtotal = 0.0;
        $legFees = [];

        foreach ($items as $row) {
            if (($row['vendor_status'] ?? '') !== 'active') {
                continue;
            }
            $vid = (int) $row['vendor_id'];
            $line = (float) $row['unit_price_ngn'] * (int) $row['quantity'];
            $subtotal += $line;
            if (!isset($byVendor[$vid])) {
                $byVendor[$vid] = [
                    'vendor_id' => $vid,
                    'store_name' => (string) ($row['store_name'] ?? 'Store'),
                    'subtotal' => 0.0,
                    'items' => [],
                ];
            }
            $byVendor[$vid]['subtotal'] += $line;
            $byVendor[$vid]['items'][] = $row;
        }

        $deliveryTotal = 0.0;
        if ($dropLat !== null && $dropLng !== null) {
            foreach (array_keys($byVendor) as $vid) {
                $v = $this->vendorModel->findById($vid);
                $pickLat = $v ? $this->nullableFloat($v['pickup_lat'] ?? null) : null;
                $pickLng = $v ? $this->nullableFloat($v['pickup_lng'] ?? null) : null;
                $fee = $this->pricing->estimateNgn($pickLat, $pickLng, $dropLat, $dropLng, 'bike', null);
                $legFees[$vid] = (float) $fee;
                $deliveryTotal += $fee;
            }
        }

        $serviceFee = $this->computeServiceFee($subtotal);
        $total = $subtotal + $deliveryTotal + $serviceFee;

        return [
            'items' => $items,
            'by_vendor' => $byVendor,
            'subtotal' => $subtotal,
            'delivery_total' => $deliveryTotal,
            'service_fee' => $serviceFee,
            'total' => $total,
            'leg_fees' => $legFees,
        ];
    }

    public function formatCartMessage(string $buyerPhone, ?float $dropLat = null, ?float $dropLng = null): string
    {
        $s = $this->summarizeCart($buyerPhone, $dropLat, $dropLng);
        if ($s['items'] === []) {
            return "🛒 Your cart is empty.\n\nType *browse* to see stores, or *shop* for the menu.";
        }
        $lines = ["🛒 *Your cart* (multi-store)\n"];
        foreach ($s['by_vendor'] as $block) {
            $lines[] = '*' . $block['store_name'] . '* — ₦' . number_format($block['subtotal'], 0);
            foreach ($block['items'] as $it) {
                $lines[] = '  • ' . $it['product_name'] . ' ×' . $it['quantity']
                    . ' — ₦' . number_format((float) $it['unit_price_ngn'] * (int) $it['quantity'], 0);
            }
            $vid = (int) $block['vendor_id'];
            if (isset($s['leg_fees'][$vid])) {
                $lines[] = '  Delivery leg: ₦' . number_format($s['leg_fees'][$vid], 0);
            }
        }
        $lines[] = '';
        $lines[] = 'Subtotal: ₦' . number_format($s['subtotal'], 0);
        if ($dropLat !== null) {
            $lines[] = 'Delivery (all legs): ₦' . number_format($s['delivery_total'], 0);
            $lines[] = 'Service fee: ₦' . number_format($s['service_fee'], 0);
            $lines[] = '*Total: ₦' . number_format($s['total'], 0) . '*';
        } else {
            $lines[] = '_Share delivery location at checkout for delivery fees._';
        }
        $lines[] = "\nType *checkout* to pay, or *browse* to add more.";

        return implode("\n", $lines);
    }

    private function computeServiceFee(float $subtotal): float
    {
        $pct = (float) ($_ENV['MARKETPLACE_SERVICE_FEE_PCT'] ?? getenv('MARKETPLACE_SERVICE_FEE_PCT') ?: '2');
        $min = (float) ($_ENV['MARKETPLACE_SERVICE_FEE_MIN_NGN'] ?? getenv('MARKETPLACE_SERVICE_FEE_MIN_NGN') ?: '200');
        $max = (float) ($_ENV['MARKETPLACE_SERVICE_FEE_MAX_NGN'] ?? getenv('MARKETPLACE_SERVICE_FEE_MAX_NGN') ?: '2000');
        $fee = $subtotal * ($pct / 100);

        return max($min, min($max, round($fee, 2)));
    }

    private function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }
}
