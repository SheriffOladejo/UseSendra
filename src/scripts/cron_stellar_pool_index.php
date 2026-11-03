<?php

/**
 * Credit classic USDC paid to Sendra's shared G + memo into the user's C wallet.
 *
 * Disable: SENDRA_STELLAR_POOL_INDEX=0
 *
 * Crontab (every minute, 4× per minute):
 *   * * * * * /var/www/html/coinectra/scripts/run_stellar_pool_index_15s.sh >> /var/log/sendra_stellar_pool_index.log 2>&1
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$enabled = strtolower(trim((string) ($_ENV['SENDRA_STELLAR_POOL_INDEX'] ?? getenv('SENDRA_STELLAR_POOL_INDEX') ?: '1')));
if (in_array($enabled, ['0', 'false', 'off', 'no'], true)) {
    echo "SENDRA_STELLAR_POOL_INDEX disabled; exit.\n";
    exit(0);
}

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '';
$bot = $bots[$aptosPhoneId] ?? null;
if ($bot === null) {
    fwrite(STDERR, "Unknown WHATSAPP_APTOS_PHONE_NUMBER_ID\n");
    exit(1);
}

$db = Database::getInstance($bot['db_config'], false)->getConnection();
$access = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
$wa = new \App\Services\WhatsAppService($access, $aptosPhoneId);

$stats = (new \App\Services\SendraStellarPoolIndexerService($db, $wa))->run();

echo gmdate('c') . ' stellar_pool_index fetched=' . ($stats['fetched'] ?? 0)
    . ' credited=' . ($stats['credited'] ?? 0)
    . ' unmatched=' . ($stats['unmatched'] ?? 0)
    . ' failed=' . ($stats['failed'] ?? 0)
    . ' retried=' . ($stats['retried'] ?? 0)
    . ' skipped=' . ($stats['skipped'] ?? 0)
    . "\n";

exit(0);
