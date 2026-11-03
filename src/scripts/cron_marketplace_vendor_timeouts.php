<?php

/**
 * Expire vendor accept windows → cancel sub-order only → Option A prompt.
 * Run against sendra_logistics_db (e.g. every minute).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../config/bootstrap_env.php';
require __DIR__ . '/../../config/Database.php';

$bots = require __DIR__ . '/../../config/Bots.php';
$logisticsPhoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';
$db = Database::getInstance($bots[$logisticsPhoneId]['db_config'], false)->getConnection();

$accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
$whatsapp = new \App\Services\WhatsAppService($accessToken, $logisticsPhoneId);

use App\Models\StoreOrder;
use App\Services\Marketplace\MarketplacePartialFulfillService;
use App\Services\Marketplace\MarketplaceRefundNotifier;
use App\Models\MarketplaceOrder;
use App\Models\Vendor;
use App\Services\Marketplace\VendorThrottleService;

$storeOrderModel = new StoreOrder($db);
$partial = new MarketplacePartialFulfillService(
    new MarketplaceOrder($db),
    $storeOrderModel,
    new Vendor($db),
    new MarketplaceRefundNotifier(new MarketplaceOrder($db), $whatsapp),
    new VendorThrottleService(new Vendor($db)),
    new \App\Models\Session($db)
);

foreach ($storeOrderModel->listAwaitingVendorPastDeadline() as $so) {
    $storeOrderModel->update((int) $so['id'], [
        'status' => 'cancelled',
        'cancel_reason' => 'vendor_timeout',
    ]);
    (new VendorThrottleService(new Vendor($db)))->recordVendorCancel((int) $so['vendor_id'], 'vendor_timeout');
    $partial->maybePromptBuyer((int) $so['marketplace_order_id']);
    echo 'Cancelled SO-' . $so['id'] . " (timeout)\n";
}
