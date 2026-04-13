<?php

// Paystack charge.success only (X-Paystack-Signature). Centiiv / Svix webhooks → CentiivWebhook.php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/html/php-debug.log');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';
require __DIR__ . '/config/Database.php';

$config = require __DIR__ . '/config/Paystack.php';
$bots = require __DIR__ . '/config/Bots.php';

use App\Models\DeliveryRequest;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Services\LogisticsAfterDriverFeePaidService;
use App\Services\WhatsAppService;

$logisticsPhoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';

$db = Database::getInstance($bots[$logisticsPhoneId]['db_config'], false)->getConnection();

$accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$deliveryRequestModel = new DeliveryRequest($db);
$driverModel = new LogisticsDriver($db);
$userModel = new LogisticsUser($db);
$whatsappService = new WhatsAppService($accessToken, $logisticsPhoneId);

$payload = @file_get_contents('php://input');
$event = json_decode($payload, true);

error_log('PAYSTACK WEBHOOK: ' . $payload);

$secret = $config['secret_key'];
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if ($signature !== hash_hmac('sha512', (string) $payload, $secret)) {
    http_response_code(401);
    exit('Invalid signature');
}

if (($event['event'] ?? '') !== 'charge.success') {
    http_response_code(200);
    exit('OK');
}

$data = $event['data'] ?? [];
$metadata = $data['metadata'] ?? [];
if (is_object($metadata)) {
    $metadata = (array) $metadata;
}

if (($metadata['type'] ?? null) !== 'driver_acceptance_fee') {
    http_response_code(200);
    exit('OK');
}

$requestId = (int) ($metadata['request_id'] ?? 0);
$driverId = (int) ($metadata['driver_id'] ?? 0);
$reference = (string) ($data['reference'] ?? '');

$finalize = new LogisticsAfterDriverFeePaidService($whatsappService, __DIR__);
$finalize->run(
    $db,
    $requestId,
    $driverId,
    $reference,
    $data,
    $metadata,
    $deliveryRequestModel,
    $driverModel,
    $userModel
);

http_response_code(200);
echo 'OK';
