<?php
/**
 * Backfill per-wallet Nodit deposit webhooks for all users with an Aptos address.
 *
 *   php src/scripts/sync_nodit_deposit_webhooks.php
 *   php src/scripts/sync_nodit_deposit_webhooks.php --phone=2348164727987
 *   php src/scripts/sync_nodit_deposit_webhooks.php --dry-run
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../config/bootstrap_env.php';

use App\Services\NoditWebhookService;

$dryRun = in_array('--dry-run', $argv, true);
$phoneFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--phone=')) {
        $phoneFilter = substr($arg, 8);
    }
}

$nodit = new NoditWebhookService();
if (!$nodit->isConfigured()) {
    fwrite(STDERR, "Set NODIT_API_KEY and NODIT_WEBHOOK_LISTENER_URL (or SENDRA_PUBLIC_BASE_URL).\n");
    exit(1);
}

$indexer = new \App\Services\NoditIndexerDepositService();
$gqlErr = $indexer->checkConnection();
if ($gqlErr === null) {
    echo "Nodit Indexer GraphQL OK: {$indexer->getGraphqlUrl()}\n";
} else {
    echo "WARN: Nodit Indexer GraphQL: {$gqlErr}\n";
}

$apiCheck = $nodit->checkAptosWebhookApi();
if ($apiCheck !== null) {
    fwrite(STDERR, "NOTE: {$apiCheck}\n\n");
}

$webhookApiOk = ($apiCheck === null);
if (!$webhookApiOk && !$dryRun) {
    fwrite(STDERR, "Skipping per-wallet Webhook API registration (use console webhook + aptos_indexer_webhook.php cron).\n");
    fwrite(STDERR, "To force API registration anyway: NODIT_FORCE_WEBHOOK_API=1\n\n");
    if (strtolower(trim((string) ($_ENV['NODIT_FORCE_WEBHOOK_API'] ?? getenv('NODIT_FORCE_WEBHOOK_API') ?: '0'))) !== '1') {
        exit(0);
    }
}

$host = $_ENV['APTOS_DB_HOST'] ?? getenv('APTOS_DB_HOST') ?: 'localhost';
$name = $_ENV['APTOS_DB_NAME'] ?? getenv('APTOS_DB_NAME') ?: 'sendra_db';
$user = $_ENV['APTOS_DB_USER'] ?? getenv('APTOS_DB_USER') ?: 'developer';
$pass = $_ENV['APTOS_DB_PASS'] ?? getenv('APTOS_DB_PASS') ?: '';

$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sql = "SELECT * FROM users WHERE aptos_address IS NOT NULL AND aptos_address != ''";
$params = [];
if ($phoneFilter !== null) {
    $sql .= ' AND phone_number = :phone';
    $params['phone'] = $phoneFilter;
}
$sql .= ' ORDER BY Id ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ok = 0;
$fail = 0;

foreach ($rows as $row) {
    $phone = $row['phone_number'];
    $addr = $row['aptos_address'];
    echo ($dryRun ? '[dry-run] ' : '') . "User {$phone} {$addr}\n";

    if ($dryRun) {
        continue;
    }

    $subs = $nodit->syncUserRow($row);
    if ($subs === null) {
        $err = $nodit->getLastError() ?? 'unknown';
        echo "  WARN: no subscriptions created — {$err}\n";
        $fail++;
        continue;
    }

    $upd = $pdo->prepare('UPDATE users SET nodit_webhook_subscriptions = :json WHERE phone_number = :phone');
    $upd->execute([
        'json' => json_encode($subs),
        'phone' => $phone,
    ]);
    echo '  OK: ' . json_encode($subs) . "\n";
    $ok++;
}

echo "\nDone. synced={$ok} failed={$fail} total=" . count($rows) . "\n";
echo "Disable the old global 0x0-only webhook in the Nodit console when satisfied.\n";
