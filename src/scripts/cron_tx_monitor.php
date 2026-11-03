<?php

/**
 * Score recent Aptos transactions and email configured recipients.
 *
 * Crontab example, every 5 minutes:
 *   php /var/www/sendra/wallet/src/scripts/cron_tx_monitor.php
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

use App\Services\SendraTxMonitorService;

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '';
$bot = $bots[$aptosPhoneId] ?? null;
if ($bot === null) {
    fwrite(STDERR, "Unknown WHATSAPP_APTOS_PHONE_NUMBER_ID\n");
    exit(1);
}

$db = Database::getInstance($bot['db_config'], false)->getConnection();
$sent = (new SendraTxMonitorService($db))->scan();
fwrite(STDOUT, 'emails_sent=' . $sent . PHP_EOL);
