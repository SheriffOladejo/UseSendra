<?php

declare(strict_types=1);

/**
 * Generate a Sendra-controlled voucher escrow wallet and print the env lines to save.
 *
 * Usage:
 *   php /var/www/html/coinectra/src/scripts/generate_voucher_escrow_wallet.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

$secret = trim((string) ($_ENV['INTERNAL_API_SECRET'] ?? getenv('INTERNAL_API_SECRET') ?: ''));
if ($secret === '') {
    fwrite(STDERR, "INTERNAL_API_SECRET is not set.\n");
    exit(1);
}

$ch = curl_init('http://127.0.0.1:3001/accounts/create');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['phone_number' => 'voucher_escrow_wallet']),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Internal-Secret: ' . $secret,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$error = $errno ? curl_error($ch) : '';
curl_close($ch);

$decoded = json_decode((string) $raw, true);
if (!is_array($decoded) || empty($decoded['success'])) {
    fwrite(STDERR, "Could not create escrow wallet.\n");
    if ($error !== '') {
        fwrite(STDERR, "cURL: {$error}\n");
    }
    fwrite(STDERR, "Response: " . (string) $raw . "\n");
    exit(1);
}

echo "Save these env values:\n\n";
echo 'SENDRA_VOUCHER_ESCROW_ADDRESS=' . (string) $decoded['aptos_address'] . PHP_EOL;
echo 'SENDRA_VOUCHER_ESCROW_ENCRYPTED_PRIVATE_KEY=' . (string) $decoded['encrypted_private_key'] . PHP_EOL;
