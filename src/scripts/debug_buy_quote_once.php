<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$phone = $argv[1] ?? '2348105461947';
$payNgn = (float) ($argv[2] ?? 600);
$asset = strtoupper($argv[3] ?? 'USDT');

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '';
$bot = $bots[$aptosPhoneId] ?? null;
if ($bot === null) {
    fwrite(STDERR, "Unknown bot\n");
    exit(1);
}

$db = Database::getInstance($bot['db_config'], false)->getConnection();
$stmt = $db->prepare('SELECT aptos_address FROM users WHERE phone_number = ? LIMIT 1');
$stmt->execute([$phone]);
$aptos = (string) ($stmt->fetchColumn() ?: '');
echo "phone={$phone} aptos=" . substr($aptos, 0, 24) . "... pay={$payNgn} asset={$asset}\n";

$svc = new \App\Services\SendraBuyRampService();
echo 'centiiv=' . ($svc->isCentiivConfigured() ? 'yes' : 'no')
    . ' 1click=' . ($svc->isOneClickBridgeConfigured() ? 'yes' : 'no') . "\n";

$t0 = microtime(true);
$q = $svc->quoteNairaBuy($payNgn, $asset, $aptos);
$elapsed = round(microtime(true) - $t0, 2);
echo "elapsed={$elapsed}s success=" . ($q['success'] ? '1' : '0') . ' error=' . ($q['error'] ?? '') . "\n";
if ($q['success']) {
    echo 'receive=' . ($q['receive_crypto_display'] ?? '') . ' centiiv_usdc=' . ($q['centiiv_usdc'] ?? '') . "\n";
}
