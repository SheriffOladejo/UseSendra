<?php

declare(strict_types=1);

/**
 * Manual Centiiv NGN bank disburse from Sendra pool account.
 *
 * Usage: php src/scripts/manual_centiiv_disburse.php <amount_ngn> <account> <bank_code> [tracking_id] [description]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

use App\Services\SendraCentiivRampService;

$amountNgn = (float) ($argv[1] ?? 0);
$account = trim((string) ($argv[2] ?? ''));
$bankCode = trim((string) ($argv[3] ?? ''));
$trackingId = trim((string) ($argv[4] ?? ''));
$description = trim((string) ($argv[5] ?? ''));

if ($amountNgn < 100 || strlen($account) !== 10 || $bankCode === '') {
    fwrite(STDERR, "Usage: php src/scripts/manual_centiiv_disburse.php <amount_ngn> <account> <bank_code> [tracking_id] [description]\n");
    exit(2);
}

if ($trackingId === '') {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $trackingId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

if ($description === '') {
    $description = 'Sendra manual payout ₦' . number_format($amountNgn, 0, '.', ',') . ' to ' . $account;
}

$agg = new SendraCentiivRampService();
if (!$agg->isConfigured()) {
    fwrite(STDERR, "Centiiv is not configured.\n");
    exit(3);
}

echo "tracking={$trackingId}\n";
echo 'amount=NGN ' . number_format($amountNgn, 2, '.', ',') . "\n";
echo "account={$account} bank={$bankCode}\n";
echo "description={$description}\n";

$res = $agg->disburseNgnToBank($account, $bankCode, $amountNgn, $trackingId, $description);

echo 'success=' . (!empty($res['success']) ? '1' : '0') . ' http=' . ($res['http_code'] ?? 0) . "\n";
if (!empty($res['error'])) {
    echo 'error=' . $res['error'] . "\n";
}
if (!empty($res['raw'])) {
    echo 'raw=' . $res['raw'] . "\n";
}
echo 'data=' . json_encode($res['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

exit(!empty($res['success']) ? 0 : 1);
