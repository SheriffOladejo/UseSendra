<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

use App\Models\DeliveryRequest;
use App\Models\DeliveryOffer;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Models\LogisticsReview;
use App\Models\Session;
use App\Services\WhatsAppService;

$host = $_ENV['LOGISTICS_DB_HOST'] ?? getenv('LOGISTICS_DB_HOST') ?: 'localhost';
$name = $_ENV['LOGISTICS_DB_NAME'] ?? getenv('LOGISTICS_DB_NAME') ?: 'sendra_logistics_db';
$user = $_ENV['LOGISTICS_DB_USER'] ?? getenv('LOGISTICS_DB_USER') ?: 'developer';
$pass = $_ENV['LOGISTICS_DB_PASS'] ?? getenv('LOGISTICS_DB_PASS') ?: '';
$phoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';
$token = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$db = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$deliveryRequestModel = new DeliveryRequest($db);
$deliveryOfferModel = new DeliveryOffer($db);
$driverModel = new LogisticsDriver($db);
$logisticsReviewModel = new LogisticsReview($db);
$userModel = new LogisticsUser($db);
$sessionModel = new Session($db);
$whatsappService = new WhatsAppService($token, $phoneId);

$pendingRequests = $deliveryOfferModel->findRequestsWithPendingOffers();

foreach ($pendingRequests as $requestId) {

    $request = $deliveryRequestModel->findById($requestId);
    if (!$request) {
        continue;
    }

    $customer = $userModel->findById($request['user_id']);
    if (!$customer) {
        continue;
    }

    $offers = $deliveryOfferModel->findPendingByRequest($requestId);
    if (empty($offers)) {
        continue;
    }

    $message = "💸 Drivers have sent counteroffers for your delivery.\nEach offer shows that rider's average stars.\n\n";
    $buttons = [];

    foreach ($offers as $offer) {
        $driver = $driverModel->findById($offer['driver_id']);
        if (!$driver) {
            continue;
        }
        $driverName = $driver['first_name'];
        $price = (int) $offer['proposed_price'];

        $sum = $logisticsReviewModel->summaryForDriver((int) $offer['driver_id']);
        $riderStars = LogisticsReview::formatAverageStarsLine('This rider', $sum);
        $bodyText = "🚚 {$driverName}\n{$riderStars}\n₦" . number_format($price, 0) . "\n\nHide this rider: block_rider_{$driver['id']}";
        $buttons = [
            "accepting_offer_{$requestId}_{$offer['id']}" => "✅ Accept ₦{$price}",
        ];

        $deliveryOfferModel->updateStatus($offer['id'], 'inreview');

        $whatsappService->sendButtonMessage($customer['phone'], $bodyText, $buttons);
    }

    $message .= "\nTap an offer soon — open slots can refresh as riders update.";
    $whatsappService->sendMessage($customer['phone'], $message);

    $sessionData = $sessionModel->findByPhone($customer['phone']) ?? [];
    $sessionData['pending_delivery_request'] = $requestId;

    $sessionModel->update($customer['phone'], $sessionData);
    $sessionModel->updateStep($customer['phone'], 'customer_choose_offer');
}

$expiredOffers = $deliveryOfferModel->findExpiredOffers(5 * 60);
foreach ($expiredOffers as $offer) {
    $deliveryOfferModel->updateStatus($offer['id'], 'rejected');
    $driver = $driverModel->findById($offer['driver_id']);
    if ($driver) {
        $whatsappService->sendMessage(
            $driver['phone'],
            "⚠️ Your counteroffer for delivery #{$offer['request_id']} has expired."
        );
    }
}
