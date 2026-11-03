<?php

/**
 * Credit inbound Arc USDC to the user's wallet.
 *
 * Disable: SENDRA_ARC_INDEX=0
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$enabled = strtolower(trim((string) ($_ENV['SENDRA_ARC_INDEX'] ?? getenv('SENDRA_ARC_INDEX') ?: '1')));
if (in_array($enabled, ['0', 'false', 'off', 'no'], true)) {
    echo "SENDRA_ARC_INDEX disabled; exit.\n";
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

$stats = (new \App\Services\SendraArcIndexerService($db, $wa))->run();

echo gmdate('c') . ' arc_index logs=' . ($stats['logs'] ?? 0)
    . ' credited=' . ($stats['credited'] ?? 0)
    . ' skipped=' . ($stats['skipped'] ?? 0)
    . ' failed=' . ($stats['failed'] ?? 0)
    . "\n";

exit(0);
