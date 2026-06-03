<?php

/**
 * Render Sendra withdrawal receipt PNGs (standard layout) for QA / reissue.
 *
 * Usage:
 *   php src/scripts/generate_receipt_preview.php
 *   php src/scripts/generate_receipt_preview.php --victor
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

$outDir = dirname(__DIR__, 2) . '/var/receipts';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$standard = [
    'bank_name' => 'GTBANK PLC',
    'account_name' => 'OZIEH VICTOR KIKACHUKWU',
    'account_number' => '0674468051',
    'sender_name' => 'Nomba Financial Services',
    'display_reference' => 'API-TRANSFER-727AB-ad269006-7e34-4e06-b928-14f851d14807',
    'withdraw_asset' => 'USDC',
    'withdraw_amount' => 17.764,
    'amount_ngn' => 25000.0,
    'conversion_rate' => '₦1,406.25',
    'session_id' => '12ehh3urb4fihbjbeoigijgiogtbudfbvjf buerub',
    'transaction_id' => '12ehh3urb4fihbjbeoigijgiogtbudfbvjfbuerub',
    'completed_at' => 'May 04, 2026 3:18 PM',
];

$victor = [
    'bank_name' => 'PAYCOM(OPAY)',
    'account_name' => 'VICTOR KIKACHUKWU OZIEH',
    'account_number' => '8105461947',
    'sender_name' => 'Nomba Financial Services',
    'display_reference' => 'API-TRANSFER-727AB-ad269006-7e34-4e06-b928-14f851d14807',
    'withdraw_asset' => 'USDC',
    'withdraw_amount' => 1.827335,
    'amount_ngn' => 2507.22,
    'conversion_rate' => '₦1,371.50',
    'transaction_id' => '7359a4c8-7f4f-4675-80ac-f531ca8c28df',
    'completed_at' => 'May 13, 2026 10:00 AM',
];

$onlyVictor = in_array('--victor', $argv ?? [], true);
$jobs = $onlyVictor
    ? ['victor_may13' => $victor]
    : [
        'standard_gtb_may04' => $standard,
        'victor_may13' => $victor,
    ];

foreach ($jobs as $slug => $model) {
    $path = $outDir . '/sendra_receipt_' . $slug . '.png';
    $png = \App\Services\SendraPayoutReceiptImageService::renderToTempFile($model);
    if ($png === null || !is_readable($png)) {
        fwrite(STDERR, "Failed to render {$slug}\n");
        exit(1);
    }
    if (!rename($png, $path)) {
        copy($png, $path);
        @unlink($png);
    }
    echo "Wrote {$path}\n";
}

echo "Done.\n";
