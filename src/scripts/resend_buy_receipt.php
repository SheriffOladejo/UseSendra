<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

use App\Services\SendraBuyReceiptDelivery;
use App\Services\WhatsAppService;

/**
 * Re-send buy receipt for a completed on-ramp pending row.
 *
 *   php src/scripts/resend_buy_receipt.php <pending_transaction_id>
 *   php src/scripts/resend_buy_receipt.php ef3400900047279f6392909655e6c7d8
 */

$id = trim((string) ($argv[1] ?? ''));
if ($id === '') {
    fwrite(STDERR, "Usage: php src/scripts/resend_buy_receipt.php <pending_transaction_id>\n");
    exit(2);
}

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = (string) ($_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '');
$bot = $bots[$aptosPhoneId] ?? null;
if ($bot === null) {
    fwrite(STDERR, "Unknown WHATSAPP_APTOS_PHONE_NUMBER_ID\n");
    exit(1);
}

$db = Database::getInstance($bot['db_config'], false)->getConnection();
$stmt = $db->prepare(
    "SELECT user_phone, human_description, amount, currency FROM pending_transactions WHERE id = ? AND tx_type IN ('onramp_oneclick_centiiv', 'onramp_daya_aptos') LIMIT 1"
);
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    fwrite(STDERR, "Pending on-ramp row not found: {$id}\n");
    exit(3);
}

$meta = json_decode((string) ($row['human_description'] ?? ''), true);
if (!is_array($meta)) {
    fwrite(STDERR, "Invalid human_description JSON\n");
    exit(4);
}

$phone = trim((string) ($row['user_phone'] ?? ''));
$asset = (string) ($row['currency'] ?? 'USDC');
$amount = (string) ($row['amount'] ?? '');
$meta = array_merge($meta, [
    'ledger_amount' => $amount,
    'receive_crypto_display' => trim($amount) !== '' ? "{$amount} {$asset}" : ($meta['receive_crypto_display'] ?? ''),
    'buy_receipt_sent' => false,
]);

$access = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
$defaultWa = new WhatsAppService($access, $aptosPhoneId);
$phoneId = \AptosV2\Support\WhatsAppBotResolver::resolvePhoneNumberIdForOutbound(
    $db,
    $phone,
    $meta,
    $aptosPhoneId
);
$wa = ($phoneId !== '' && $phoneId !== $aptosPhoneId)
    ? new WhatsAppService($access, $phoneId)
    : $defaultWa;

if (!SendraBuyReceiptDelivery::trySend($wa, $phone, $meta, null)) {
    fwrite(STDERR, "Failed to send buy receipt.\n");
    exit(5);
}

$merge = json_encode(array_merge($meta, ['buy_receipt_sent' => true]), JSON_UNESCAPED_UNICODE);
$db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?')->execute([$merge, $id]);

fwrite(STDOUT, "OK — buy receipt sent to {$phone}\n");
