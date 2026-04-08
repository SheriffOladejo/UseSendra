<?php
require __DIR__ . '../../../vendor/autoload.php';

use App\Services\WhatsAppService;

// DB connection
$db = new PDO(
    'mysql:host=localhost;dbname=sendra_logistics_db;charset=utf8mb4',
    'developer',
    'Stipendly@2026'
);

$whatsapp = new WhatsAppService(
    "EAAnBMaDNtoUBQeFAjBMFryee3LifVRqjB4NkecAPZAnXKRKcUZA2u9JcilTaICwqa7qJJWNuQctZCH020Ir1SVzO33UcCkuP8s5H45BpyIrZAQDKnJVDS33qZAt0IlOptKZB7aO5ZBytSzYgTTMLFI0e4yrtpp0MyGqkMKbSw4bvMPUA5l91ZCUEZBb4saoChLQZDZD",
    "1097784993409171"
);

echo "Cron started...\n";

/*
|--------------------------------------------------------------------------
| 1. REMIND DRIVERS WITH INCOMPLETE ONBOARDING
|--------------------------------------------------------------------------
*/

$query = $db->query("
    SELECT phone_number, current_step
    FROM whatsapp_sessions
    WHERE current_step IS NOT NULL
    AND current_step NOT IN ('rider_ready','in_review','rider_approved')
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

    echo "Onboarding reminder → {$phone} ({$currentStep})\n";

    $nextStepMessage = $stepsMap[$currentStep] ?? null;

    if (!$nextStepMessage) {
        continue;
    }

    $whatsapp->sendMessage(
        $phone,
        "Hi 👋 You haven’t completed your Sendra rider profile.\n\n".
        "{$nextStepMessage}\n\n".
        "Send *hi* to continue or message 07019240234 if you have any questions."
    );
}


/*
|--------------------------------------------------------------------------
| 2. REMIND APPROVED DRIVERS WITHOUT LOCATION
|--------------------------------------------------------------------------
*/

$locationQuery = $db->query("
    SELECT phone, first_name
    FROM drivers
    WHERE verification_status = 'approved'
    AND (preferred_latitude IS NULL OR preferred_longitude IS NULL)
");

while ($row = $locationQuery->fetch(PDO::FETCH_ASSOC)) {

    $phone = $row['phone'];
    $firstName = $row['first_name'] ?? '';

    echo "Location reminder → {$phone}\n";

    $whatsapp->sendMessage(
        $phone,
        "Hi {$firstName}! 🎉 Your Sendra rider account is approved.\n\n".
        "To start receiving delivery requests, please set your *preferred bus-stop location*.\n\n".
        "This helps Sendra send you deliveries close to where you are.\n\n".
        "Use Whatsapp's location📍 feature to select the location on the map\n1. Tap the '+' icon\n2. Select 'Location'\n3. Choose 'Send your current location' or pick a spot on the map\n\n".
        "You can update this anytime as you change locations.\n\n"
    );
}

echo "Cron finished\n";