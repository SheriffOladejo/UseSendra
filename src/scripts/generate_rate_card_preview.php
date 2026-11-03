<?php

/**
 * Render Sendra live rate-card PNG from Centiiv wallet/rates.
 *
 * Usage:
 *   php src/scripts/generate_rate_card_preview.php
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

$outDir = dirname(__DIR__, 2) . '/var/receipts';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$model = \App\Services\SendraRateCardFactory::build();
if (empty($model['success'])) {
    fwrite(STDERR, 'Failed to fetch rates: ' . ($model['error'] ?? 'unknown') . "\n");
    exit(1);
}

$png = \App\Services\SendraPayoutReceiptImageService::renderRatesToTempFile($model);
if ($png === null || !is_readable($png)) {
    fwrite(STDERR, "Failed to render rate card PNG\n");
    exit(1);
}

$path = $outDir . '/sendra_rate_card.png';
if (!rename($png, $path)) {
    copy($png, $path);
    @unlink($png);
}

echo "Wrote {$path}\n";
echo $model['text'] . "\n";
echo "Done.\n";
