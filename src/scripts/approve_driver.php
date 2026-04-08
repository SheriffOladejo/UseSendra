<?php

require __DIR__ . '../../../vendor/autoload.php';

/**
 * 1️⃣ Create DB connection
 */

$pdo = new PDO(
    "mysql:host=localhost;dbname=sendra_logistics_db;charset=utf8mb4",
    "developer",
    "Stipendly@2026",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]
);

/**
 * 2️⃣ Initialize models/services
 */

$sessionModel = new \App\Models\Session($pdo);
$driverModel  = new \App\Models\LogisticsDriver($pdo);
$whatsapp     = new \App\Services\WhatsAppService("EAAnBMaDNtoUBQeFAjBMFryee3LifVRqjB4NkecAPZAnXKRKcUZA2u9JcilTaICwqa7qJJWNuQctZCH020Ir1SVzO33UcCkuP8s5H45BpyIrZAQDKnJVDS33qZAt0IlOptKZB7aO5ZBytSzYgTTMLFI0e4yrtpp0MyGqkMKbSw4bvMPUA5l91ZCUEZBb4saoChLQZDZD", "1097784993409171");

/**
 * 3️⃣ Get phone argument
 */

$phone = $argv[1] ?? null;

if (!$phone) {
    echo "Usage: php approve_driver.php 2348012345678\n";
    exit;
}

/**
 * 4️⃣ Find driver
 */

$driver = $driverModel->findByPhone($phone);

if (!$driver) {
    echo "Driver not found\n";
    exit;
}

/**
 * 5️⃣ Approve driver
 */

$driverModel->update($driver['phone'], [
    'verification_status' => 'approved'
]);

/**
 * 6️⃣ Update WhatsApp session step
 */

$sessionModel->updateStep($phone, 'rider_location_preference');

/**
 * 7️⃣ Send confirmation message
 */

$message =
"✅ *Profile Verified!*\n\n".
"Your rider profile has been approved.\n\n".
"Next step: Please send your favorite bus-stop location so we can start sending delivery requests near you.\n\n".
"Use Whatsapp's location📍 feature to select the location on the map\n1. Tap the '+' icon\n2. Select 'Location'\n3. Choose 'Send your current location' or pick a spot on the map\n\nNo worries, you can always change it anytime\n\nSend a message to 07019240234 if you have any questions.";

$whatsapp->sendMessage($phone, $message);

echo "Driver approved successfully.\n";