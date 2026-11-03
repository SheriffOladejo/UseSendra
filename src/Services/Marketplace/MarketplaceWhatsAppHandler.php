<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceCartItem;
use App\Models\MarketplaceOrder;
use App\Models\Session;
use App\Models\StoreOrder;
use App\Models\Vendor;
use App\Models\VendorProduct;
use App\Services\DeliveryPricingService;
use App\Models\LogisticsDriver;
use App\Services\WhatsAppService;
use PDO;

/**
 * Marketplace commands (shop, cart, vendor buttons, partial fulfill).
 */
final class MarketplaceWhatsAppHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly WhatsAppService $whatsapp,
        private readonly Session $sessionModel
    ) {
    }

    public static function build(PDO $db, WhatsAppService $whatsapp): self
    {
        return new self($db, $whatsapp, new Session($db));
    }

    /**
     * @return bool true if message was handled
     */
    public function tryHandle(string $phone, string $messageLower, string $rawMessage, array $session): bool
    {
        $flowSteps = [
            'shop_checkout_location', 'shop_checkout_receiver',
            'vendor_store_name', 'vendor_pickup_location',
            'vendor_add_product_name', 'vendor_add_product_price', 'vendor_menu',
        ];
        if (in_array($session['current_step'] ?? '', $flowSteps, true)) {
            if (!str_starts_with($messageLower, 'vendor_') && !preg_match('/^add_\d+/', $messageLower)) {
                return false;
            }
        }

        if (str_starts_with($messageLower, 'vendor_accept_')) {
            $id = (int) str_replace('vendor_accept_', '', $messageLower);
            $this->vendorOrder()->accept($id, $phone);

            return true;
        }
        if (str_starts_with($messageLower, 'vendor_reject_')) {
            $id = (int) str_replace('vendor_reject_', '', $messageLower);
            $r = $this->vendorOrder()->reject($id, $phone);
            if (($r['message'] ?? '') !== '') {
                $this->whatsapp->sendMessage($phone, $r['message']);
            }

            return true;
        }
        if (str_starts_with($messageLower, 'vendor_ready_')) {
            $id = (int) str_replace('vendor_ready_', '', $messageLower);
            $r = $this->vendorOrder()->markReady($id, $phone);
            $this->whatsapp->sendMessage($phone, $r['message'] !== '' ? $r['message'] : ($r['ok'] ? '✅' : '⚠️'));

            return true;
        }
        if (str_starts_with($messageLower, 'receiver_unavailable_')) {
            $drId = (int) str_replace('receiver_unavailable_', '', $messageLower);
            $r = $this->failedDelivery()->markReceiverUnavailable($drId, $phone);
            $this->whatsapp->sendMessage($phone, $r['message']);

            return true;
        }
        if (preg_match('/^add_(\d+)(?:_(\d+))?$/', $messageLower, $m)) {
            $pid = (int) $m[1];
            $qty = isset($m[2]) ? (int) $m[2] : 1;
            $r = $this->cart()->addProduct($phone, $pid, $qty);
            $this->whatsapp->sendMessage($phone, $r['ok'] ? "✅ Added to cart.\n\n" . $this->cart()->formatCartMessage($phone) : '⚠️ ' . ($r['error'] ?? 'Could not add.'));
            return true;
        }
        if (preg_match('/^store_(\d+)$/', $messageLower, $m)) {
            $this->showStoreProducts($phone, (int) $m[1]);

            return true;
        }

        $step = $session['current_step'] ?? '';
        if ($step === 'marketplace_partial_fulfill') {
            $d = $this->getSessionData($session);
            $mid = (int) ($d['pending_marketplace_order_id'] ?? 0);
            if ($mid > 0) {
                $r = $this->partialFulfill()->handleBuyerChoice($mid, $messageLower);
                $this->whatsapp->sendMessage($phone, $r['message']);
                if ($r['ok']) {
                    $this->sessionModel->updateStep($phone, 'shop_menu');
                }
            }

            return true;
        }

        $commands = [
            'shop', 'buy', 'cart', 'browse', 'checkout', 'orders', 'sell', 'vendor',
            'continue', 'cancel all', 'cancel_all', 'cancelall',
        ];
        if (!in_array($messageLower, $commands, true) && !str_starts_with($messageLower, 'shop')) {
            return false;
        }

        if (in_array($messageLower, ['continue', 'cancel all', 'cancel_all', 'cancelall'], true)) {
            $d = $this->getSessionData($session);
            $mid = (int) ($d['pending_marketplace_order_id'] ?? 0);
            if ($mid <= 0) {
                $this->whatsapp->sendMessage($phone, 'No order awaiting your choice. Type *orders*.');

                return true;
            }
            $r = $this->partialFulfill()->handleBuyerChoice($mid, $messageLower);
            $this->whatsapp->sendMessage($phone, $r['message']);

            return true;
        }

        if ($messageLower === 'sell' || $messageLower === 'vendor') {
            $this->showVendorMenu($phone);

            return true;
        }

        if ($messageLower === 'shop' || $messageLower === 'buy') {
            $this->sessionModel->updateStep($phone, 'shop_menu');
            $this->whatsapp->sendMessage(
                $phone,
                "🛍️ *Sendra Shop*\n\n"
                . "• *browse* — see stores\n"
                . "• *cart* — view cart (multi-store)\n"
                . "• *checkout* — pay online & order\n"
                . "• *orders* — track purchases\n"
                . "• *send* — ship a package (delivery only)\n\n"
                . "To add items: open a store, then tap a product id or type *add_{product_id}*"
            );

            return true;
        }

        if ($messageLower === 'browse') {
            $this->showBrowse($phone);

            return true;
        }

        if ($messageLower === 'cart') {
            $d = $this->getSessionData($session);
            $lat = isset($d['checkout_delivery_lat']) ? (float) $d['checkout_delivery_lat'] : null;
            $lng = isset($d['checkout_delivery_lng']) ? (float) $d['checkout_delivery_lng'] : null;
            $this->whatsapp->sendMessage($phone, $this->cart()->formatCartMessage($phone, $lat, $lng));

            return true;
        }

        if ($messageLower === 'checkout') {
            $this->sessionModel->updateStep($phone, 'shop_checkout_location');
            $this->whatsapp->sendMessage(
                $phone,
                "📍 *Checkout*\n\nShare your *delivery location* using WhatsApp location (📎 → Location).\n"
                . "We'll sum delivery fees per store leg."
            );

            return true;
        }

        if ($messageLower === 'orders') {
            $this->showBuyerOrders($phone);

            return true;
        }

        return false;
    }

    private function showBrowse(string $phone): void
    {
        $vendors = (new Vendor($this->db))->listBrowsable(15);
        if ($vendors === []) {
            $this->whatsapp->sendMessage($phone, 'No stores listed yet. Check back soon or type *sell* to open a store.');

            return;
        }
        $lines = ["🏪 *Stores*\n"];
        foreach ($vendors as $v) {
            $lines[] = '*' . $v['store_name'] . '* — type *store_' . $v['id'] . '*';
        }
        $this->whatsapp->sendMessage($phone, implode("\n", $lines));
    }

    private function showStoreProducts(string $phone, int $vendorId): void
    {
        $vendor = (new Vendor($this->db))->findById($vendorId);
        if (!$vendor || ($vendor['status'] ?? '') !== 'active') {
            $this->whatsapp->sendMessage($phone, 'Store not available.');

            return;
        }
        $products = (new VendorProduct($this->db))->listByVendor($vendorId);
        if ($products === []) {
            $this->whatsapp->sendMessage($phone, ($vendor['store_name'] ?? 'Store') . " has no products yet.");

            return;
        }
        $lines = ['🛒 *' . $vendor['store_name'] . "*\n"];
        foreach ($products as $p) {
            $lines[] = $p['name'] . ' — ₦' . number_format((float) $p['price_ngn'], 0)
                . "\n  type *add_" . $p['id'] . '* or *add_' . $p['id'] . '_2* for qty';
        }
        $this->whatsapp->sendMessage($phone, implode("\n\n", $lines));
    }

    private function showVendorMenu(string $phone): void
    {
        $vendor = (new Vendor($this->db))->findByPhone($phone);
        if (!$vendor) {
            $this->sessionModel->updateStep($phone, 'vendor_store_name');
            $this->whatsapp->sendMessage($phone, "🏪 *Sell on Sendra*\n\nWhat's your *store name*?");

            return;
        }
        $this->sessionModel->updateStep($phone, 'vendor_menu');
        $this->whatsapp->sendMessage(
            $phone,
            '🏪 *' . $vendor['store_name'] . "*\n"
            . 'Status: ' . ($vendor['status'] ?? '') . "\n"
            . 'Wallet: ₦' . number_format((float) ($vendor['wallet_balance_ngn'] ?? 0), 0) . "\n\n"
            . "• *add product* — list a new item\n"
            . "• *my products* — view catalog\n"
            . "• *store open* / *store closed*"
        );
    }

    private function showBuyerOrders(string $phone): void
    {
        $stmt = $this->db->prepare("
            SELECT id, status, total_ngn, partial_fulfill_status, created_at
            FROM marketplace_orders
            WHERE buyer_phone = :phone
            ORDER BY id DESC LIMIT 8
        ");
        $stmt->execute(['phone' => $phone]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $this->whatsapp->sendMessage($phone, 'No shop orders yet. Type *browse* to start.');

            return;
        }
        $lines = ["📦 *Your shop orders*\n"];
        foreach ($rows as $r) {
            $lines[] = '#M-' . $r['id'] . ' — ' . $r['status'] . ' — ₦' . number_format((float) $r['total_ngn'], 0);
        }
        $pending = null;
        foreach ($rows as $r) {
            if (($r['partial_fulfill_status'] ?? '') === 'pending') {
                $pending = (int) $r['id'];
                break;
            }
        }
        if ($pending !== null) {
            $d = $this->getSessionDataFromPhone($phone);
            $d['pending_marketplace_order_id'] = $pending;
            $this->saveSessionData($phone, $d);
            $this->sessionModel->updateStep($phone, 'marketplace_partial_fulfill');
            $lines[] = "\n⚠️ Order #M-{$pending} needs *continue* or *cancel all*.";
        }
        $this->whatsapp->sendMessage($phone, implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function getSessionData(array $session): array
    {
        $raw = $session['data'] ?? ($session['session_data'] ?? '{}');
        if (!is_string($raw)) {
            return [];
        }
        $j = json_decode($raw, true);

        return is_array($j) ? $j : [];
    }

    private function getSessionDataFromPhone(string $phone): array
    {
        $s = $this->sessionModel->findByPhone($phone);

        return $s ? $this->getSessionData($s) : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveSessionData(string $phone, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->sessionModel->update($phone, ['data' => $json]);
    }

    private function cart(): MarketplaceCartService
    {
        return new MarketplaceCartService(
            new MarketplaceCartItem($this->db),
            new Vendor($this->db),
            new VendorProduct($this->db),
            DeliveryPricingService::fromEnv(),
            new LogisticsDriver($this->db),
            new VendorThrottleService(new Vendor($this->db))
        );
    }

    private function vendorOrder(): MarketplaceVendorOrderService
    {
        return new MarketplaceVendorOrderService(
            new StoreOrder($this->db),
            new \App\Models\StoreOrderItem($this->db),
            new MarketplaceOrder($this->db),
            new Vendor($this->db),
            new VendorThrottleService(new Vendor($this->db)),
            $this->partialFulfillService(),
            $this->dispatch(),
            $this->whatsapp
        );
    }

    private function partialFulfill(): MarketplacePartialFulfillService
    {
        return new MarketplacePartialFulfillService(
            new MarketplaceOrder($this->db),
            new StoreOrder($this->db),
            new Vendor($this->db),
            $this->refundNotifier(),
            new VendorThrottleService(new Vendor($this->db)),
            $this->sessionModel
        );
    }

    private function refundNotifier(): MarketplaceRefundNotifier
    {
        return new MarketplaceRefundNotifier(new MarketplaceOrder($this->db), $this->whatsapp);
    }

    private function dispatch(): MarketplaceDeliveryDispatchService
    {
        return new MarketplaceDeliveryDispatchService(
            new \App\Models\DeliveryRequest($this->db),
            new MarketplaceOrder($this->db),
            new StoreOrder($this->db),
            new \App\Models\StoreOrderItem($this->db),
            new Vendor($this->db),
            new LogisticsDriver($this->db),
            $this->whatsapp
        );
    }

    private function failedDelivery(): MarketplaceFailedDeliveryService
    {
        return new MarketplaceFailedDeliveryService(
            new \App\Models\DeliveryRequest($this->db),
            new StoreOrder($this->db),
            new MarketplaceOrder($this->db),
            new Vendor($this->db),
            new \App\Models\VendorWalletTransaction($this->db),
            $this->dispatch(),
            $this->refundNotifier(),
            $this->partialFulfillService(),
            $this->whatsapp
        );
    }

    public function checkout(): MarketplaceCheckoutService
    {
        return new MarketplaceCheckoutService(
            new MarketplaceCartItem($this->db),
            new MarketplaceOrder($this->db),
            new StoreOrder($this->db),
            new \App\Models\StoreOrderItem($this->db),
            new \App\Models\LogisticsUser($this->db),
            $this->cart(),
            new \App\Services\CentiivDirectPayService()
        );
    }

    public function afterPayment(): MarketplaceAfterPaymentService
    {
        return new MarketplaceAfterPaymentService(
            new MarketplaceOrder($this->db),
            new StoreOrder($this->db),
            new MarketplaceCartItem($this->db),
            new Vendor($this->db),
            $this->whatsapp
        );
    }

    public function partialFulfillService(): MarketplacePartialFulfillService
    {
        return $this->partialFulfill();
    }
}
