<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); // FIXED: was 1 — PHP errors in response body break WhatsApp's 200 check
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/html/php-debug.log');

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config/Paystack.php';

use App\Models\DeliveryRequest;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Services\WhatsAppService;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$db = new PDO(
    'mysql:host=localhost;dbname=sendra_logistics_test_db;charset=utf8mb4',
    'developer',
    'Stipendly@2026'
);

$deliveryRequestModel = new DeliveryRequest($db);
$driverModel = new LogisticsDriver($db);
$userModel = new LogisticsUser($db);
$whatsappService = new WhatsAppService("EAAnBMaDNtoUBQeFAjBMFryee3LifVRqjB4NkecAPZAnXKRKcUZA2u9JcilTaICwqa7qJJWNuQctZCH020Ir1SVzO33UcCkuP8s5H45BpyIrZAQDKnJVDS33qZAt0IlOptKZB7aO5ZBytSzYgTTMLFI0e4yrtpp0MyGqkMKbSw4bvMPUA5l91ZCUEZBb4saoChLQZDZD", "1097784993409171");

$payload = @file_get_contents("php://input");
$event = json_decode($payload, true);

error_log("PAYSTACK WEBHOOK: " . $payload);

$secret = $config['secret_key'];

$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if ($signature !== hash_hmac('sha512', $payload, $secret)) {
    http_response_code(401);
    exit('Invalid signature');
}

if ($event['event'] === 'charge.success') {

    $data = $event['data'];
    $metadata = $data['metadata'];

    if (($metadata['type'] ?? null) !== 'driver_acceptance_fee') exit;

    $requestId = $metadata['request_id'];
    $driverId  = $metadata['driver_id'];

    $request = $deliveryRequestModel->findById($requestId);
    if (!$request || $request['status'] === 'driver_assigned') exit;

    $driver = $driverModel->findById($driverId);
    $sender = $userModel->findById($request['user_id']);

    // 1️⃣ Generate QR for the customer
    $qrPath = $deliveryRequestModel->generateQRCode("SENDRA_PICKUP_{$requestId}");

    // 2️⃣ Update delivery request in DB
    $deliveryRequestModel->update($requestId, [
        'status' => 'driver_assigned',
        'reserved_driver_id' => $driverId,
        'pickup_qr_code' => $qrPath,
    ]);

    // 3️⃣ Customer message with QR + delivery info
    $customerMessage = "🎉 A courier has accepted your delivery!\n\n" .
                       "Pickup: {$request['pickup_address']}\n" .
                       "Dropoff: {$request['dropoff_address']}\n" .
                       "Item: {$request['item_name']}\n" .
                       "Description: {$request['item_description']}\n" .
                       "Price: ₦" . number_format($request['offered_price'], 0) . "\n\n" .
                       "Courier: {$driver['first_name']} {$driver['last_name']}\n" .
                       "Phone: {$driver['preferred_phone_number']}\n" .
                       "Vehicle: {$driver['vehicle_color']} {$driver['vehicle_type']}\n" .
                       "Plate: {$driver['license_number']}";

    $whatsappService->sendMessage($sender['phone'], $customerMessage);
    $qrPath = generatePickupQR($requestId);
    // If generateQRCode returns a local file path, make sure it exists
    if (!file_exists($qrPath)) {
        error_log("QR file does not exist: $qrPath");
    }

    // Try sending and log result
    $sent = $whatsappService->sendImage($sender['phone'], $qrPath, "🔐 Pickup QR Code\nShow this to the courier when they arrive.");
    // 4️⃣ Driver message — all delivery info except QR
    $pickupLink = "https://www.google.com/maps/search/?api=1&query={$request['pickup_lat']},{$request['pickup_lng']}";
    $dropoffLink = "https://www.google.com/maps/search/?api=1&query={$request['dropoff_lat']},{$request['dropoff_lng']}";

    $driverMessage = "✅ Payment confirmed! You have been assigned this delivery.\n\n" .
                     "Pickup Address: {$request['pickup_address']}\n" .
                     "Google Maps: {$pickupLink}\n\n" .
                     "Dropoff Address: {$request['dropoff_address']}\n" .
                     "Google Maps: {$dropoffLink}\n\n" .
                     "Item: {$request['item_name']}\n" .
                     "Description: {$request['item_description']}\n\n" .
                     "Sender Phone: {$request['sender_phone']}\n" .
                     "Receiver Phone: {$request['receiver_phone']}\n\n" .
                     "Price: ₦" . number_format($request['offered_price'], 0) . "\n\n" .
                     "Courier Vehicle: {$driver['vehicle_color']} {$driver['vehicle_type']}\n" .
                     "Plate: {$driver['license_number']}\n\n" .
                     "You will need to scan the QR code at pickup (sent to customer).";

    $whatsappService->sendMessage($driver['phone'], $driverMessage);

    // 5️⃣ Send item photo to driver if exists
    if (!empty($request['item_photo_url'])) {
        $whatsappService->sendImage($driver['phone'], $request['item_photo_url'], "📦 Item photo");
    }
}

function generatePickupQR(int $requestId): string
{
    $data = "SENDRA_PICKUP_" . $requestId;

    $qrCode = new QrCode(
        data: $data,
        size: 300,
        margin: 10
    );

    $writer = new PngWriter();
    $result = $writer->write($qrCode);

    $projectRoot = dirname(__DIR__, 2);
    $dir = '/var/www/html/coinectra/uploads/qrcodes';

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file = $dir . "/delivery_{$requestId}.png";

    $result->saveToFile($file);

    return "uploads/qrcodes/delivery_{$requestId}.png";
}

?>