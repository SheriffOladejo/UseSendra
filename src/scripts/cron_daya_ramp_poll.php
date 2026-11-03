<?php

/**
 * Complete Daya Aptos buy/sell rows when a deposit exists but the webhook was missed.
 *
 *   * * * * * php /var/www/html/coinectra/src/scripts/cron_daya_ramp_poll.php >> /var/log/daya_ramp_poll.log 2>&1
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

use App\Services\SendraDayaWebhookService;

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '';
$bot = $bots[$aptosPhoneId] ?? null;
if ($bot === null) {
    fwrite(STDERR, "Unknown WHATSAPP_APTOS_PHONE_NUMBER_ID\n");
    exit(1);
}

$db = Database::getInstance($bot['db_config'], false)->getConnection();
$n = (new SendraDayaWebhookService($db))->reconcileOpenRows(48);
echo gmdate('c') . " daya ramp poll completed={$n}\n";
