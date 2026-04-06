<?php
require __DIR__ . '/vendor/autoload.php';

use App\Models\Session;
use App\Models\LogisticsDriver;
use App\Services\WhatsAppService;

// DB connection
$db = new PDO('mysql:host=localhost;dbname=sendra_logistics_db;charset=utf8mb4', 'developer', 'Stipendly@2026');

$sessionModel = new Session($db);
$driverModel  = new LogisticsDriver($db);
$whatsapp     = new WhatsAppService("EAAnBMaDNtoUBQeFAjBMFryee3LifVRqjB4NkecAPZAnXKRKcUZA2u9JcilTaICwqa7qJJWNuQctZCH020Ir1SVzO33UcCkuP8s5H45BpyIrZAQDKnJVDS33qZAt0IlOptKZB7aO5ZBytSzYgTTMLFI0e4yrtpp0MyGqkMKbSw4bvMPUA5l91ZCUEZBb4saoChLQZDZD", "1097784993409171");

// Fetch all drivers with incomplete profile
$query = $db->query("SELECT ws.phone_number, ws.current_step, d.first_name
                     FROM whatsapp_sessions ws
                     JOIN drivers d ON ws.phone_number = d.phone
                     WHERE ws.current_step IS NOT NULL AND ws.current_step != 'rider_ready'");

$stepsMap = [
    'rider_name' => 'Please enter your full name.',
    'rider_phone' => 'Please confirm your phone number.',
    'vehicle_type' => 'Select your vehicle type.',
    'plate_number' => 'Enter your vehicle plate number.',
    'driver_id' => 'Upload your driver license photo.',
    'vehicle_picture' => 'Upload a picture of your vehicle.',
    'vehicle_color' => 'Enter your vehicle color.',
    'vehicle_brand' => 'Enter your vehicle brand.',
    'nin' => 'Enter your NIN number.',
    'rider_location_preference' => 'Set your preferred operating locations.',
    'confirm_location' => 'Confirm your location.',
    'in_review' => 'Your profile is under review.'
];

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $phone = $row['phone_number'];
    $currentStep = $row['current_step'];
    $firstName = $row['first_name'] ?? '';

    $nextStepMessage = $stepsMap[$currentStep] ?? 'Please continue completing your profile.';
    
    $whatsapp->sendMessage(
        $phone,
        "Hi {$firstName}! 👋 You haven’t completed your profile yet. {$nextStepMessage} Send *hi* to continue."
    );
}