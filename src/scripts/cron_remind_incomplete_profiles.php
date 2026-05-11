<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

use App\Services\WhatsAppMessagingWindow;
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
    $pass
);

$whatsapp = new WhatsAppService($token, $phoneId);

echo "Cron started...\n";

/*
|--------------------------------------------------------------------------
| 1. REMIND DRIVERS WITH INCOMPLETE ONBOARDING
|--------------------------------------------------------------------------
*/

// Modified query to include last_message_at for window checking
$query = $db->query("
    SELECT
        ws.phone_number,
        ws.current_step,
        d.last_message_at,
        ws.updated_at AS session_updated_at
    FROM whatsapp_sessions ws
    LEFT JOIN drivers d ON d.phone = ws.phone_number
    WHERE ws.current_step IS NOT NULL
    AND ws.current_step NOT IN ('rider_ready','rider_approved')
");

$stepsMap = [
    'choose_role' => 'Please choose your role to get started.',
    'rider_name' => 'Please enter your full name.',
    'rider_phone' => 'Please confirm your phone number.',
    'vehicle_type' => 'Select your vehicle type.',
    'plate_number' => 'Enter your vehicle plate number.',
    'driver_id' => 'Upload your driver license photo.',
    'vehicle_picture' => 'Upload a picture of your vehicle.',
    'vehicle_color' => 'Enter your vehicle color.',
    'vehicle_brand' => 'Enter your vehicle brand.',
    'nin' => 'Enter your NIN number.',
    'rider_location_preference' => 'Set your preferred operating bus-stop location.',
    'confirm_location' => 'Confirm your preferred bus-stop location.',
];

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {

    $phone = $row['phone_number'];
    $currentStep = $row['current_step'];
    $lastDriverTouch = $row['last_message_at'] ?? null;
    $sessionUpdated = $row['session_updated_at'] ?? null;
    $lastTouch = (is_string($lastDriverTouch) && $lastDriverTouch !== '')
        ? $lastDriverTouch
        : (is_string($sessionUpdated) ? $sessionUpdated : null);

    echo "Onboarding reminder → {$phone} ({$currentStep})\n";

    $nextStepMessage = $stepsMap[$currentStep] ?? null;

    if (!$nextStepMessage) {
        continue;
    }

    $messageText = 
        "Hi 👋 You haven't completed your Sendra rider profile.\n\n".
        "{$nextStepMessage}\n\n".
        "Send *hi* to continue or message 07019240234 if you have any questions.";

    if (WhatsAppMessagingWindow::isWithinCustomerCareWindow($lastTouch)) {
        $whatsapp->sendMessage($phone, $messageText);
        echo "  → Sent regular message\n";
    } else {
        $whatsapp->sendTemplate(
            $phone,
            'incomplete_onboarding_reminder', // Create this template in Meta
            [$nextStepMessage]
        );
        echo "  → Sent template (outside ~23h30m window)\n";
    }
}


/*
|--------------------------------------------------------------------------
| 2. REMIND APPROVED DRIVERS WITHOUT LOCATION
|--------------------------------------------------------------------------
*/

$locationQuery = $db->query("
    SELECT 
        phone, 
        first_name,
        last_message_at
    FROM drivers
    WHERE verification_status = 'approved'
    AND (preferred_latitude IS NULL OR preferred_longitude IS NULL)
");

while ($row = $locationQuery->fetch(PDO::FETCH_ASSOC)) {

    $phone = $row['phone'];
    $firstName = $row['first_name'] ?? '';
    $lastMessageAt = $row['last_message_at'];

    echo "Location reminder → {$phone}\n";

    $messageText = 
        "Hi {$firstName}! 🎉 Your Sendra rider account is approved.\n\n".
        "To start receiving delivery requests, please set your *preferred bus-stop location*.\n\n".
        "This helps Sendra send you deliveries close to where you are.\n\n".
        "Use Whatsapp's location📍 feature to select the location on the map\n1. Tap the '+' icon\n2. Select 'Location'\n3. Choose 'Send your current location' or pick a spot on the map\n\n".
        "You can update this anytime as you change locations.\n\n";

    if (WhatsAppMessagingWindow::isWithinCustomerCareWindow($lastMessageAt)) {
        // Send regular message
        $whatsapp->sendMessage($phone, $messageText);
        echo "  → Sent regular message\n";
    } else {
        // Send template
        $whatsapp->sendTemplate(
            $phone,
            'location_setup_reminder', // Create this template in Meta
            [$firstName]
        );
        echo "  → Sent template (outside ~23h30m window)\n";
    }
}

echo "Cron finished\n";