<?php

/**
 * Process pending buy quote jobs (Centiiv + 1Click dry) off the WhatsApp webhook path.
 *
 * Disable: SENDRA_BUY_QUOTE_POLL=0
 *
 * Crontab (every minute, 4× per minute):
 *   * * * * * /var/www/html/coinectra/scripts/run_buy_quote_worker_15s.sh >> /var/log/sendra_buy_quote_poll.log 2>&1
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$enabled = strtolower(trim((string) ($_ENV['SENDRA_BUY_QUOTE_POLL'] ?? getenv('SENDRA_BUY_QUOTE_POLL') ?: '1')));
if (in_array($enabled, ['0', 'false', 'off', 'no'], true)) {
    echo "SENDRA_BUY_QUOTE_POLL disabled; exit.\n";
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

$batch = max(1, min(25, (int) ($_ENV['SENDRA_BUY_QUOTE_BATCH_SIZE'] ?? getenv('SENDRA_BUY_QUOTE_BATCH_SIZE') ?: 10)));
$worker = new \App\Services\SendraBuyQuoteWorkerService($db, $wa);
$stats = $worker->run($batch);

$purgeDays = max(1, (int) ($_ENV['SENDRA_WA_DEDUP_PURGE_DAYS'] ?? getenv('SENDRA_WA_DEDUP_PURGE_DAYS') ?: 7));
$purged = \App\Services\SendraWhatsAppInboundDedupService::purgeOlderThan($db, $purgeDays);

echo gmdate('c') . ' buy_quote_poll processed=' . $stats['processed']
    . ' done=' . $stats['done']
    . ' failed=' . $stats['failed']
    . ' skipped=' . $stats['skipped']
    . ' expired=' . $stats['expired']
    . ' recovered=' . ($stats['recovered'] ?? 0)
    . ' dedup_purged=' . $purged . "\n";

exit(0);
