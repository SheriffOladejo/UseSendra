<?php
// Include composer autoloader if you use composer
require __DIR__ . '../../../vendor/autoload.php';

// Or manually include your models/services
require __DIR__ . '/../Models/DeliveryRequest.php';
require __DIR__ . '/../Models/DeliveryOffer.php';
require __DIR__ . '/../Models/LogisticsDriver.php';
require __DIR__ . '/../Models/LogisticsUser.php';
require __DIR__ . '/../Models/Session.php';
require __DIR__ . '/../Services/WhatsAppService.php';

// Create database connection manually
$db = new PDO(
    'mysql:host=localhost;dbname=sendra_logistics_db;charset=utf8mb4',
    'developer',
    'Stipendly@2026',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Instantiate your models/services
$deliveryRequestModel = new App\Models\DeliveryRequest($db);
$deliveryOfferModel   = new App\Models\DeliveryOffer($db);
$driverModel          = new App\Models\LogisticsDriver($db);
$userModel            = new App\Models\LogisticsUser($db);
$sessionModel         = new App\Models\Session($db);
$whatsappService     = new \App\Services\WhatsAppService("EAAnBMaDNtoUBQeFAjBMFryee3LifVRqjB4NkecAPZAnXKRKcUZA2u9JcilTaICwqa7qJJWNuQctZCH020Ir1SVzO33UcCkuP8s5H45BpyIrZAQDKnJVDS33qZAt0IlOptKZB7aO5ZBytSzYgTTMLFI0e4yrtpp0MyGqkMKbSw4bvMPUA5l91ZCUEZBb4saoChLQZDZD", "1097784993409171");

// 1️⃣ Fetch requests with pending counteroffers
$pendingRequests = $deliveryOfferModel->findRequestsWithPendingOffers();

foreach ($pendingRequests as $requestId) {

    $request = $deliveryRequestModel->findById($requestId);
    if (!$request) continue;

    $customer = $userModel->findById($request['user_id']);
    if (!$customer) continue;

    $offers = $deliveryOfferModel->findPendingByRequest($requestId);
    if (empty($offers)) continue;

    $message = "💸 Drivers have sent counteroffers for your delivery:\n\n";
    $buttons = [];

    $rows = [];

    foreach ($offers as $offer) {
        $driver = $driverModel->findById($offer['driver_id']);
        $driverName = $driver['first_name'];
        $price = (int) $offer['proposed_price'];

        $bodyText = "🚚 {$driverName} has offered ₦" . number_format($price, 0) . " for your delivery.";
        $buttons = [
            "accepting_offer_{$requestId}_{$offer['id']}" => "✅ Accept ₦{$price}"
        ];

        //$offer->updateStatus($offer['id'], 'inreview');
        $deliveryOfferModel->updateStatus($offer['id'], 'inreview');

        $whatsappService->sendButtonMessage($customer['phone'], $bodyText, $buttons);
    }

    $message .= "\nYou have 90 seconds to accept one offer or make a counteroffer.";
    $whatsappService->sendMessage($customer['phone'], $message);

    // Update session so customer can respond
    $sessionData = $sessionModel->findByPhone($customer['phone']) ?? [];
    $sessionData['pending_delivery_request'] = $requestId;

    $sessionModel->update($customer['phone'], $sessionData);
    $sessionModel->updateStep($customer['phone'], 'customer_choose_offer');
}

// Expire old offers (>5 min)
$expiredOffers = $deliveryOfferModel->findExpiredOffers(5 * 60);
foreach ($expiredOffers as $offer) {
    $deliveryOfferModel->updateStatus($offer['id'], 'rejected');
    $driver = $driverModel->findById($offer['driver_id']);
    $whatsappService->sendMessage(
        $driver['phone'],
        "⚠️ Your counteroffer for delivery #{$offer['request_id']} has expired."
    );
}