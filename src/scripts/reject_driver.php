<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Services\WhatsAppService;

$host = $_ENV['LOGISTICS_DB_HOST'] ?? getenv('LOGISTICS_DB_HOST') ?: 'localhost';
$name = $_ENV['LOGISTICS_DB_NAME'] ?? getenv('LOGISTICS_DB_NAME') ?: 'sendra_logistics_db';
$user = $_ENV['LOGISTICS_DB_USER'] ?? getenv('LOGISTICS_DB_USER') ?: 'developer';
$pass = $_ENV['LOGISTICS_DB_PASS'] ?? getenv('LOGISTICS_DB_PASS') ?: '';
$phoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';
$token = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$pdo = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
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
$whatsapp = new WhatsAppService($token, $phoneId);

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