<?php

declare(strict_types=1);

/**
 * Recover intermediary disburse + receipt for a fulfilled Centiiv order (missed webhook).
 *
 * Usage: php src/scripts/recover_intermediary_disburse.php <centiiv_order_id> [phone]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

use App\Services\SendraCentiivRampService;
use App\Services\SendraCentiivPayoutReceiptFactory;
use App\Services\SendraPayoutReceiptImageService;
use App\Services\WhatsAppService;

$orderId = trim((string) ($argv[1] ?? ''));
$phoneOverride = trim((string) ($argv[2] ?? ''));
if ($orderId === '') {
    fwrite(STDERR, "Usage: php src/scripts/recover_intermediary_disburse.php <centiiv_order_id> [phone]\n");
    exit(2);
}

$botConfigs = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = (string) ($_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834');
$accessToken = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
$botConfig = $botConfigs[$aptosPhoneId] ?? reset($botConfigs);
$db = Database::getInstance($botConfig['db_config'], useReplica: false)->getConnection();
$wa = new WhatsAppService($accessToken, $aptosPhoneId);
$agg = new SendraCentiivRampService();

$stmt = $db->prepare(
    'SELECT user_phone, human_description FROM pending_transactions
     WHERE human_description LIKE :needle ORDER BY created_at DESC LIMIT 1'
);
$stmt->execute(['needle' => '%' . $orderId . '%']);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "No pending row for order {$orderId}\n");
    exit(3);
}

$phone = $phoneOverride !== '' ? $phoneOverride : (string) ($row['user_phone'] ?? '');
$meta = json_decode((string) ($row['human_description'] ?? ''), true);
if (!is_array($meta)) {
    $meta = [];
}
$sellCtx = is_array($meta['sell_ctx'] ?? null) ? $meta['sell_ctx'] : [];

$requested = SendraCentiivRampService::resolveIntermediaryRequestedNgnFromPendingMeta($meta, $sellCtx);
$userBank = SendraCentiivRampService::resolveIntermediaryUserBankFromPendingMeta($meta, $sellCtx);
$userAcct = $userBank['account_number'];
$userCode = $userBank['bank_code'];
$userName = $userBank['account_name'];
$userBankName = $userBank['bank_name'];

$req = $agg->getRequest($orderId);
if (!$req['success']) {
    fwrite(STDERR, 'getRequest failed: ' . ($req['error'] ?? 'unknown') . "\n");
    exit(4);
}
$data = is_array($req['data'] ?? null) ? $req['data'] : [];
$orderData = is_array($data['data'] ?? null) ? $data['data'] : $data;
$receivedNgn = null;
foreach ($orderData['fulfillments'] ?? [] as $f) {
    if (!is_array($f)) {
        continue;
    }
    $amt = (float) ($f['amount'] ?? 0);
    if ($amt > 0) {
        $receivedNgn = $amt;
        break;
    }
}

$poolMin = SendraCentiivRampService::intermediaryPoolMinimumReceivedNgn($requested);
echo "order={$orderId} phone={$phone} requested={$requested} received=" . ($receivedNgn ?? 'null') . " pool_min={$poolMin}\n";

if ($requested <= 0 || strlen($userAcct) !== 10 || $userCode === '') {
    fwrite(STDERR, "Missing user bank or requested NGN\n");
    exit(5);
}
if ($receivedNgn === null || !SendraCentiivRampService::intermediaryPoolCoversUserPayout($requested, $receivedNgn)) {
    fwrite(STDERR, "Pool shortfall — cannot disburse (need pool_min={$poolMin})\n");
    exit(6);
}

$existing = SendraCentiivRampService::loadIntermediaryDisburseContextForOrder($orderId);
if (is_array($existing) && !empty($existing['disburse_tracking_id'])) {
    echo "Disburse already queued tracking=" . $existing['disburse_tracking_id'] . "\n";
    $ctx = $existing;
} else {
    $trackingId = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
    $quotedNgn = isset($meta['sendra_quoted_receive_ngn']) ? (float) $meta['sendra_quoted_receive_ngn'] : null;
    $feeNgn = 0.0;
    if ($receivedNgn > $requested) {
        $feeNgn = round($receivedNgn - $requested, 2);
    } elseif ($quotedNgn !== null && $quotedNgn > $requested) {
        $feeNgn = round($quotedNgn - $requested, 2);
    }
    $ctx = [
        'user_phone' => $phone,
        'centiiv_order_id' => $orderId,
        'requested_ngn' => $requested,
        'received_ngn' => $receivedNgn,
        'quoted_receive_ngn' => $quotedNgn,
        'transaction_fee_ngn' => $feeNgn,
        'user_account_number' => $userAcct,
        'user_bank_code' => $userCode,
        'user_account_name' => $userName,
        'user_bank_name' => $userBankName,
        'sendra_payout_reference' => (string) ($meta['sendra_payout_reference'] ?? ''),
        'sendra_ledger_asset' => (string) ($meta['sendra_ledger_asset'] ?? $row['currency'] ?? 'USDT'),
        'sendra_ledger_amount' => (string) ($meta['sendra_ledger_amount'] ?? $meta['sendra_ledger_amount'] ?? ''),
        'disburse_tracking_id' => $trackingId,
        'centiiv_order_rate' => SendraCentiivRampService::extractOfficialNgnRateFromOrder(
            $orderData
        ),
    ];
    if ($ctx['sendra_ledger_amount'] === '') {
        $ctx['sendra_ledger_amount'] = (string) ($meta['sendra_ledger_amount'] ?? '');
    }
    SendraCentiivRampService::cacheIntermediaryDisburseContextForOrder($orderId, $trackingId, $ctx);

    $res = $agg->disburseNgnToBank($userAcct, $userCode, $requested, $trackingId, 'Sendra WhatsApp payout recover ' . $phone);
    if (!$res['success']) {
        fwrite(STDERR, 'disburse failed: ' . ($res['error'] ?? 'unknown') . ' ' . json_encode($res['data'] ?? []) . "\n");
        exit(7);
    }
    echo "disburse queued tracking={$trackingId}\n";
}

$feeFields = SendraCentiivPayoutReceiptFactory::resolveIntermediaryReceiptFee($requested, $ctx);
$receiptModel = [
    'amount_ngn' => $requested,
    'transaction_fee_ngn' => $feeFields['transaction_fee_ngn'],
    'transaction_fee_crypto' => $feeFields['transaction_fee_crypto'],
    'transaction_fee_asset' => $feeFields['transaction_fee_asset'],
    'account_name' => $userName,
    'account_number' => $userAcct,
    'bank_name' => $userBankName,
    'withdraw_asset' => (string) ($ctx['sendra_ledger_asset'] ?? 'USDT'),
    'withdraw_amount' => is_numeric($ctx['sendra_ledger_amount'] ?? null) ? (float) $ctx['sendra_ledger_amount'] : null,
    'display_reference' => SendraCentiivPayoutReceiptFactory::resolveIntermediaryDisplayReference($ctx),
    'transaction_id' => $trackingId,
    'sender_name' => SendraCentiivPayoutReceiptFactory::resolveReceiptSenderLabel(true, $orderData),
    'completed_at' => SendraCentiivPayoutReceiptFactory::formatReceiptNow('M j, Y g:i A'),
    'conversion_rate' => SendraCentiivPayoutReceiptFactory::formatIntermediaryReceiptConversionRate($ctx),
];

$path = SendraPayoutReceiptImageService::renderToTempFile($receiptModel);
if ($path === null) {
    fwrite(STDERR, "Failed to render receipt\n");
    exit(8);
}
$img = $wa->sendImage($phone, $path, '✅ Sendra — bank payout receipt');
@unlink($path);
echo 'receipt sent success=' . ($img['success'] ?? false ? '1' : '0') . "\n";
