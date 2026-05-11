<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

use App\Services\SendraCentiivPayoutReceiptFactory;
use App\Services\SendraPayoutReceiptImageService;
use App\Services\WhatsAppService;

/**
 * Usage:
 *   php src/scripts/resend_receipt.php <phone> <centiiv_order_id> [oneclick_correlation_id]
 *
 * Example:
 *   php src/scripts/resend_receipt.php 2347019240234 51af57dc-0ccc-4d71-822c-8cdc7cea068b 68b7b559-37ae-4ed1-8d01-f9504eeee3f4
 */

$phone = $argv[1] ?? '';
$orderId = $argv[2] ?? '';
$oneclick = $argv[3] ?? '';

if (!is_string($phone) || trim($phone) === '' || !is_string($orderId) || trim($orderId) === '') {
    fwrite(STDERR, "Usage: php src/scripts/resend_receipt.php <phone> <centiiv_order_id> [oneclick_correlation_id]\n");
    exit(2);
}
$phone = trim($phone);
$orderId = trim($orderId);
$oneclick = is_string($oneclick) ? trim($oneclick) : '';

$access = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '');
$phoneNumberId = (string) ($_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? '');
if ($access === '' || $phoneNumberId === '') {
    fwrite(STDERR, "Missing WHATSAPP_ACCESS_TOKEN or WHATSAPP_APTOS_PHONE_NUMBER_ID in env.\n");
    exit(3);
}

// Minimal event envelope; factory will GET /requests/{id} to enrich when configured.
$event = [
    'data' => [
        'id' => $orderId,
        'status' => 'FULFILLED',
    ],
    'timestamp' => gmdate('c'),
];

$norm = [
    'user_phone' => $phone,
    'status' => 'FULFILLED',
    'order_label' => $orderId,
    'oneclick_correlation_id' => $oneclick,
];

$model = SendraCentiivPayoutReceiptFactory::build($event, $norm);
$path = SendraPayoutReceiptImageService::renderToTempFile($model);
if ($path === null) {
    fwrite(STDERR, "Failed to render receipt image.\n");
    exit(4);
}

$wa = new WhatsAppService($access, $phoneNumberId);
$res = $wa->sendImage($phone, $path, '✅ Sendra — bank payout receipt');
@unlink($path);

if ($res === false) {
    fwrite(STDERR, "WhatsApp sendImage failed (upload failed). Check php-debug.log.\n");
    exit(5);
}
if (is_array($res) && empty($res['success'])) {
    fwrite(STDERR, "WhatsApp sendImage API error.\n");
    fwrite(STDERR, json_encode($res, JSON_UNESCAPED_SLASHES) . "\n");
    exit(6);
}

fwrite(STDOUT, "OK\n");

