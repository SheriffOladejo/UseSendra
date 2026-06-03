<?php

declare(strict_types=1);

/**
 * Verify that required 1Click asset IDs exist in /v0/tokens.
 *
 * Usage:
 *   php scripts/oneclick_verify_tokens.php
 */

$baseUrl = rtrim((string) (getenv('SENDRA_ONECLICK_BASE_URL') ?: 'https://1click.chaindefuser.com'), '/');
$jwt = (string) (getenv('SENDRA_ONECLICK_JWT') ?: '');
if (trim($jwt) === '') {
    fwrite(STDERR, "Missing env: SENDRA_ONECLICK_JWT\n");
    exit(2);
}

$required = [
    'SENDRA_ONECLICK_APTOS_USDT_ASSET_ID' => (string) (getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'),
    'SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID' => (string) (getenv('SENDRA_ONECLICK_STELLAR_USDC_ASSET_ID') ?: 'nep245:v2_1.omni.hot.tg:1100_111bzQBB65GxAPAVoxqmMcgYo5oS3txhqs1Uh1cgahKQUeTUq1TJu'),
];

$url = $baseUrl . '/v0/tokens';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $jwt,
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
]);
$raw = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrNo = curl_errno($ch);
$curlErr = $curlErrNo ? curl_error($ch) : null;
curl_close($ch);

if ($curlErrNo) {
    fwrite(STDERR, "curl error: {$curlErr}\n");
    exit(3);
}
if ($raw === false || $raw === '') {
    fwrite(STDERR, "Empty response (HTTP {$http})\n");
    exit(4);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON (HTTP {$http}). First 200 chars:\n" . substr((string) $raw, 0, 200) . "\n");
    exit(5);
}

$ids = [];
foreach ($data as $row) {
    if (is_array($row) && isset($row['assetId']) && is_string($row['assetId'])) {
        $ids[$row['assetId']] = true;
    }
}

echo "1Click base: {$baseUrl}\n";
echo "HTTP: {$http}\n";
echo "Tokens: " . count($data) . "\n\n";

$ok = true;
foreach ($required as $name => $id) {
    $present = isset($ids[$id]);
    echo "{$name}={$id} => " . ($present ? "FOUND" : "MISSING") . "\n";
    if (!$present) {
        $ok = false;
    }
}

exit($ok ? 0 : 1);

