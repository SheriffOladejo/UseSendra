<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

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

$sessionModel = new \App\Models\Session($pdo);
$driverModel = new \App\Models\LogisticsDriver($pdo);
$whatsapp = new \App\Services\WhatsAppService($token, $phoneId);

/**
 * 3️⃣ Get phone argument
 */

$phone = $argv[1] ?? null;
$mode = strtolower(trim((string) ($argv[2] ?? '')));

if (!$phone) {
    echo "Usage: php approve_driver.php 2348012345678 [approved|test|paused]\n";
    echo "  (default) approved — production courier pool\n";
    echo "  test      — approved_test; only gets jobs when LOGISTICS_TEST_COURIERS_ONLY=true\n";
    echo "  paused    — approved_paused; approved but never pinged (ops/testing)\n";
    exit;
}

if ($mode === 'test') {
    $verificationStatus = 'approved_test';
} elseif ($mode === 'paused' || $mode === 'pause') {
    $verificationStatus = 'approved_paused';
} else {
    $verificationStatus = 'approved';
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
    'verification_status' => $verificationStatus,
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
"Use WhatsApp's location📍 feature to select the location on the map\n".
"1. Tap the '➕' icon (iOS) or the '📎' icon (Android)\n".
"2. Select 'Location'\n".
"3. Choose 'Send your current location' or pick a spot on the map\n\n".
"No worries, you can always change it anytime\n\n".
"Send a message to 07019240234 if you have any questions.";

$whatsapp->sendMessage($phone, $message);

echo "Driver set to {$verificationStatus} successfully.\n";