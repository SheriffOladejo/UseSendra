<?php

/**
 * Profile live Sendra ramp API latencies (reads .env via bootstrap).
 * Usage: php src/scripts/profile_sendra_ramp_timings.php
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

function msSince(float $start): float
{
    return round((microtime(true) - $start) * 1000, 1);
}

function envInt(string $key, int $default): int
{
    $raw = $_ENV[$key] ?? getenv($key);
    if ($raw === false || $raw === null || $raw === '') {
        return $default;
    }

    return (int) $raw;
}

function envFloat(string $key, float $default): float
{
    $raw = $_ENV[$key] ?? getenv($key);
    if ($raw === false || $raw === null || $raw === '') {
        return $default;
    }

    return (float) $raw;
}

$probeRefund = trim((string) ($_ENV['SENDRA_RAMP_HEALTH_PROBE_REFUND_APTOS'] ?? getenv('SENDRA_RAMP_HEALTH_PROBE_REFUND_APTOS') ?: '0x0000000000000000000000000000000000000000000000000000000000000001'));
$stellarDeposit = trim((string) ($_ENV['SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT'] ?? getenv('SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT') ?: ''));
$originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
$destStellarUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'));

$quoteWait = envInt('SENDRA_ONECLICK_QUOTE_WAIT_MS', 8000);
$searchWait = envInt('SENDRA_ONECLICK_SEARCH_QUOTE_WAIT_MS', 0);
if ($searchWait <= 0) {
    $searchWait = min(4000, $quoteWait > 0 ? $quoteWait : 4000);
}
$quoteRetry = envInt('SENDRA_ONECLICK_QUOTE_RETRY_WAIT_MS', 15000);
$maxIters = envInt('SENDRA_SELL_TARGET_NGN_MAX_ITERS', 8);
$tolerance = envFloat('SENDRA_SELL_TARGET_NGN_TOLERANCE', 1.0);

echo "=== Sendra ramp timing profile ===\n";
echo 'Time: ' . gmdate('c') . "\n\n";
echo "--- Effective .env (unset = code default) ---\n";
echo "SENDRA_ONECLICK_QUOTE_WAIT_MS={$quoteWait}\n";
echo "SENDRA_ONECLICK_SEARCH_QUOTE_WAIT_MS={$searchWait}\n";
echo "SENDRA_ONECLICK_QUOTE_RETRY_WAIT_MS={$quoteRetry}\n";
echo "SENDRA_SELL_TARGET_NGN_MAX_ITERS={$maxIters}\n";
echo "SENDRA_SELL_TARGET_NGN_TOLERANCE={$tolerance}\n";
echo 'SENDRA_ONECLICK_RAMP_ENABLED=' . (trim((string) ($_ENV['SENDRA_ONECLICK_RAMP_ENABLED'] ?? '1')) ?: '1') . "\n";
echo 'SENDRA_CENTIIV_STELLAR_USDC_DEPOSIT=' . ($stellarDeposit !== '' ? '(set)' : '(missing)') . "\n";
echo 'SENDRA_ONECLICK_JWT=' . (trim((string) ($_ENV['SENDRA_ONECLICK_JWT'] ?? '')) !== '' ? '(set)' : '(missing)') . "\n";
echo 'SENDRA_CENTIIV_JWT=' . (preg_match('/^pk_/', trim((string) ($_ENV['SENDRA_CENTIIV_JWT'] ?? ''))) ? 'pk_* (ok)' : '(check)') . "\n\n";

$results = [];

// Centiiv quote
$agg = new \App\Services\SendraCentiivRampService();
if ($agg->isConfigured()) {
    $t0 = microtime(true);
    $q = $agg->getQuote(['fromAsset' => 'USDC', 'toAsset' => 'NGN', 'amount' => 10.0]);
    $results['centiiv_quote_10_usdc'] = ['ms' => msSince($t0), 'ok' => $q['success'], 'http' => $q['http_code'] ?? 0, 'error' => $q['error'] ?? null];

    $t0 = microtime(true);
    $b = $agg->getBanks();
    $results['centiiv_get_banks'] = ['ms' => msSince($t0), 'ok' => $b['success'], 'http' => $b['http_code'] ?? 0];

    $t0 = microtime(true);
    $v = $agg->verifyBankAccount('000058', '0123456789');
    $results['centiiv_verify_account'] = ['ms' => msSince($t0), 'ok' => $v['success'], 'http' => $v['http_code'] ?? 0, 'error' => $v['error'] ?? null];
} else {
    $results['centiiv'] = ['skipped' => true, 'reason' => 'SENDRA_CENTIIV_JWT not set'];
}

// 1Click dry quote (10 USDT)
$oneClick = new \App\Services\SendraOneClickService();
if ($oneClick->isConfigured() && $stellarDeposit !== '') {
    $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
    $amountSmallest = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits('10', 6);
    $body = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
        $originUsdt,
        $destStellarUsdc,
        $amountSmallest,
        $stellarDeposit,
        $probeRefund,
        $deadlineIso,
        true
    );
    $t0 = microtime(true);
    $dry = $oneClick->requestQuote($body);
    $results['oneclick_dry_10_usdt'] = [
        'ms' => msSince($t0),
        'ok' => $dry['success'],
        'http' => $dry['http_code'] ?? 0,
        'error' => $dry['error'] ?? null,
        'quote_wait_ms' => $body['quoteWaitingTimeMs'] ?? null,
    ];
    if ($dry['success']) {
        $parsed = \App\Services\SendraOneClickService::parseQuoteResponse($dry['data']);
        $results['oneclick_dry_10_usdt']['est_usdc'] = $parsed['amount_out_formatted'] ?? null;
    }

    // Second dry quote back-to-back (simulates another binary-search step)
    $t0 = microtime(true);
    $body2 = $body;
    $body2['amount'] = \App\Services\SendraOneClickService::cryptoAmountToSmallestUnits('5', 6);
    $dry2 = $oneClick->requestQuote($body2);
    $results['oneclick_dry_5_usdt'] = ['ms' => msSince($t0), 'ok' => $dry2['success'], 'http' => $dry2['http_code'] ?? 0];
} else {
    $results['oneclick'] = ['skipped' => true];
}

if ($oneClick->isConfigured() && $stellarDeposit !== '' && $searchWait > 0 && $searchWait !== $quoteWait) {
    $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
    $bodySearch = \App\Services\SendraOneClickService::buildAptosToStellarUsdcQuoteBody(
        $originUsdt,
        $destStellarUsdc,
        $amountSmallest,
        $stellarDeposit,
        $probeRefund,
        $deadlineIso,
        true,
        $searchWait
    );
    $t0 = microtime(true);
    $drySearch = $oneClick->requestQuote($bodySearch);
    $results['oneclick_dry_search_wait_probe'] = [
        'ms' => msSince($t0),
        'ok' => $drySearch['success'],
        'http' => $drySearch['http_code'] ?? 0,
        'wait_ms' => $searchWait,
        'error' => $drySearch['error'] ?? null,
    ];
}

echo "--- Measured latencies ---\n";
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

$centMs = (float) ($results['centiiv_quote_10_usdc']['ms'] ?? 300);
$oneMs = (float) ($results['oneclick_dry_10_usdt']['ms'] ?? ($quoteWait + 500));
$pairMs = $oneMs + $centMs;

echo "--- Modeled send-naira USDT sizing (current defaults) ---\n";
echo "Per binary-search iteration (1Click + Centiiv): ~{$pairMs} ms\n";
$hiCheck = $pairMs;
$iterTypical = min(8, $maxIters);
$iterWorst = $maxIters;
echo "Feasibility hi-check: ~{$hiCheck} ms\n";
echo "Binary search (typical ~{$iterTypical} iters): ~" . round($iterTypical * $pairMs) . " ms\n";
echo "Binary search (worst {$iterWorst} iters): ~" . round($iterWorst * $pairMs) . " ms\n";
echo "Final lock (1Click + Centiiv): ~{$pairMs} ms\n";
$orderPath = (float) ($results['centiiv_get_banks']['ms'] ?? 200)
    + (float) ($results['centiiv_verify_account']['ms'] ?? 400)
    + $oneMs * 2; // dry + live at order time
echo "Post-sizing order path (banks+verify+dry+live 1Click, Centiiv create not measured): ~" . round($orderPath) . " ms\n";
$totalTypical = $hiCheck + $iterTypical * $pairMs + $pairMs + $orderPath;
$totalWorst = $hiCheck + $iterWorst * $pairMs + $pairMs + $orderPath;
echo "Estimated pre-PIN total (typical): ~" . round($totalTypical / 1000, 1) . " s\n";
echo "Estimated pre-PIN total (worst): ~" . round($totalWorst / 1000, 1) . " s\n";

$searchMs = (float) ($results['oneclick_dry_search_wait_probe']['ms'] ?? 0);
if ($searchMs <= 0 && isset($results['oneclick_dry_10_usdt_wait_3000ms'])) {
    $searchMs = (float) $results['oneclick_dry_10_usdt_wait_3000ms']['ms'];
}
if ($searchMs > 0) {
    $searchPair = $searchMs + $centMs;
    $typicalIters = min(6, $maxIters);
    echo "\n--- With search wait {$searchWait}ms (code + .env) ---\n";
    echo "Per search iteration: ~{$searchPair} ms\n";
    echo "Pre-PIN estimate (~{$typicalIters} search iters + order path): ~"
        . round(($searchPair + $typicalIters * $searchPair + $orderPath) / 1000, 1) . " s\n";
}

echo "\nDone.\n";
