<?php

/**
 * Daya → Sendra HTTPS webhook (HMAC SHA-256, X-Daya-Signature).
 *
 * Dashboard URL: https://usesendra.com/DayaWebhook.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/html/php-debug.log');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';
require __DIR__ . '/config/Database.php';

use App\Services\SendraDayaRampService;
use App\Services\SendraDayaWebhookService;

$payload = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_DAYA_SIGNATURE'] ?? '');
if (SendraDayaRampService::webhookSecrets() === []) {
    error_log('DAYA WEBHOOK: no SENDRA_DAYA_WEBHOOK_SECRET configured');
    http_response_code(500);
    echo json_encode(['error' => 'unconfigured']);
    exit;
}

if (!SendraDayaRampService::verifyWebhookAgainstConfiguredSecrets($payload, $signature)) {
    error_log('DAYA WEBHOOK: invalid signature');
    http_response_code(401);
    echo json_encode(['error' => 'invalid_signature']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

error_log('DAYA WEBHOOK: ' . (string) ($event['event'] ?? '') . ' id=' . (string) ($event['id'] ?? ''));

$bots = require __DIR__ . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834';
$db = Database::getInstance($bots[$aptosPhoneId]['db_config'], false)->getConnection();

try {
    (new SendraDayaWebhookService($db))->handle($event);
} catch (Throwable $e) {
    error_log('DAYA WEBHOOK handler: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
