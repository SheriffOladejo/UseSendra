<?php

declare(strict_types=1);

/**
 * Refund escrowed vouchers that expired unclaimed.
 *
 * Example crontab schedule:
 *   every 5 minutes -> php /var/www/html/coinectra/src/scripts/cron_voucher_refunds.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '';
$bot = $bots[$aptosPhoneId] ?? null;
if ($bot === null) {
    fwrite(STDERR, "Unknown WHATSAPP_APTOS_PHONE_NUMBER_ID\n");
    exit(1);
}

$db = Database::getInstance($bot['db_config'], false)->getConnection();
$accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
$whatsapp = new \App\Services\WhatsAppService($accessToken, $aptosPhoneId);
$userModel = new \App\Models\User($db);
$service = new \App\Services\SendraVoucherService(
    $db,
    $whatsapp,
    $userModel,
    new \AptosV2\Support\AptosServiceClient(),
);

if (!$service->isEscrowConfigured()) {
    echo "Voucher escrow is not configured; nothing to do.\n";
    exit(0);
}

$result = $service->refundExpired(100);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
