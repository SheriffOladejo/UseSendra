<?php

require __DIR__ . '../../../vendor/autoload.php';

use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Services\WhatsAppService;

$pdo = new PDO(
    "mysql:host=localhost;dbname=sendra_logistics_db;charset=utf8mb4",
    "developer",
    "Stipendly@2026",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]
);

$phone = $argv[1] ?? null;

if (!$phone) {
    echo "Usage: php reject_driver.php PHONE REASON1 REASON2...\n";
    exit;
}

$reasons = array_slice($argv, 2);

if (empty($reasons)) {
    echo "Provide at least one rejection reason.\n";
    exit;
}

$sessionModel = new Session($pdo);
$driverModel  = new LogisticsDriver($pdo);
$whatsapp     = new \App\Services\WhatsAppService("EAAnBMaDNtoUBQeFAjBMFryee3LifVRqjB4NkecAPZAnXKRKcUZA2u9JcilTaICwqa7qJJWNuQctZCH020Ir1SVzO33UcCkuP8s5H45BpyIrZAQDKnJVDS33qZAt0IlOptKZB7aO5ZBytSzYgTTMLFI0e4yrtpp0MyGqkMKbSw4bvMPUA5l91ZCUEZBb4saoChLQZDZD", "1097784993409171");

$driver = $driverModel->findByPhone($phone);

if (!$driver) {
    echo "Driver not found\n";
    exit;
}

/**
 * Update driver status
 */

$driverModel->update($phone, [
    'verification_status' => 'rejected',
    'rejection_reason' => json_encode($reasons)
]);

/**
 * Move WhatsApp step
 */

$sessionModel->updateStep($phone, 'driver_rejected');

/**
 * Human readable labels
 */

$labels = [
    'license_picture' => 'Driver License Photo',
    'vehicle_photo'   => 'Vehicle Photo',
    'selfie'          => 'Selfie Photo',
    'nin'             => 'NIN',
    'bvn'             => 'BVN'
];

$list = "";

foreach ($reasons as $i => $reason) {
    $label = $labels[$reason] ?? $reason;
    $list .= ($i + 1) . "️⃣ {$label}\n";
}

/**
 * Send WhatsApp message
 */

$message =
"❌ *Your rider application needs correction*\n\n".
"Our team found issues with the following:\n\n".
$list."\n".
"Please send the required documents here to continue onboarding.\n\n".
"If you need help, contact support:\n".
"https://wa.me/234XXXXXXXXXX";

$whatsapp->sendMessage($phone, $message);

echo "Driver rejected successfully\n";