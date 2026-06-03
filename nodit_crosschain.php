<?php

// nodit_webhook.php - Handle Nodit blockchain webhooks
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';

use App\Services\WebhookAuthService;

$LOG_FILE = (string) ($_ENV['NODIT_WEBHOOK_LOG'] ?? getenv('NODIT_WEBHOOK_LOG') ?: '/var/www/html/nodit-webhook.log');
@ini_set('log_errors', '1');
@ini_set('error_log', $LOG_FILE);

$DB_HOST = $_ENV['APTOS_DB_HOST'] ?? getenv('APTOS_DB_HOST') ?: 'localhost';
$DB_NAME = $_ENV['APTOS_DB_NAME'] ?? getenv('APTOS_DB_NAME') ?: 'sendra_db';
$DB_USER = $_ENV['APTOS_DB_USER'] ?? getenv('APTOS_DB_USER') ?: 'developer';
$DB_PASS = $_ENV['APTOS_DB_PASS'] ?? getenv('APTOS_DB_PASS') ?: '';

// WhatsApp webhook URL
$WHATSAPP_WEBHOOK_URL = 'https://coinectra.stipendly.app/webhook.php?action=deposit';

// Known coin types
$COIN_TYPES = [
    '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832' => 'USDC',
    '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a' => 'USDT'
];

// Log function (Nodit only — do not echo to the global error_log / php-debug.log)
function logMessage($message) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date("c") . " - " . $message . "\n", FILE_APPEND);
}

logMessage("=== Nodit Webhook Received ===");

// --- Handle incoming POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents("php://input");
    $data = json_decode($body, true);

    logMessage("=== Nodit Webhook Received ===");
    logMessage($body);

    if (!$data) {
        logMessage("ERROR: Failed to decode JSON");
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON"]);
        exit;
    }

    // Check if this is a transaction event
    if (!isset($data['eventType']) || $data['eventType'] !== 'TRANSACTION') {
        logMessage("Not a transaction event, ignoring");
        http_response_code(200);
        echo json_encode(["status" => "ignored", "reason" => "not a transaction event"]);
        exit;
    }

    // Process the transaction
    processTransaction($data);

    http_response_code(200);
    echo json_encode(["status" => "processed"]);
    exit;
}

// For GET requests, just return OK
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo "Nodit Webhook Active";
    exit;
}

// --- Process Transaction Function ---
function processTransaction($data) {
    logMessage("=== Processing Transaction ===");

    // Extract event data
    $event = $data['event'] ?? null;
    if (!$event) {
        logMessage("No event data found");
        return;
    }

    // Check if this is a deposit event
    if (!isset($event['eventType']) || $event['eventType'] !== '0x1::fungible_asset::Deposit') {
        logMessage("Not a deposit event: " . ($event['eventType'] ?? 'unknown'));
        return;
    }

    logMessage("Deposit event detected!");
    

    // Get network information
    $network = $data['network'] ?? 'unknown';
    $protocol = $data['protocol'] ?? 'unknown';

    // Process each message/transaction in the event
    $messages = $event['messages'] ?? [];
    foreach ($messages as $message) {
        if (!isset($message['events'])) {
            continue;
        }

        // Look for deposit events
        foreach ($message['events'] as $evt) {
            if ($evt['type'] === '0x1::fungible_asset::Deposit') {
                processDepositEvent($evt, $message, $network, $protocol);
            }
        }
    }
}

// --- Process Individual Deposit Event ---
function processDepositEvent($depositEvent, $message, $network, $protocol) {
    global $COIN_TYPES;
    
    logMessage("=== Processing Deposit Event ===");

    $depositData = $depositEvent['data'];
    $amount = $depositData['amount'] ?? 0;
    $store = $depositData['store'] ?? '';

    $payload = $message['payload'] ?? [];
    if (($payload['type'] ?? '') === 'script_payload') {
        $args = $payload['arguments'] ?? [];
        if (isset($args[1], $args[3]) && (string) $args[1] !== (string) $args[3]) {
            if ((string) $amount === (string) $args[3]) {
                logMessage("Skipping script_payload fee/auxiliary deposit leg amount=$amount");
                return;
            }
        }
    }

    // Try to get recipient address from multiple sources
    $recipientAddress = null;
    $currency = 'UNKNOWN';

    // Method 1: Check for custom stablecoin event (works for some USDC transactions)
    foreach ($message['events'] as $evt) {
        if ($evt['type'] === '0x72d1e6aa6a648a3afc5d45d8d66b353f1e1837a728c0813beec77f28a697fa7a::stablecoin::Deposit') {
            $recipientAddress = $evt['data']['store_owner'] ?? null;
            $currency = 'USDC';
            logMessage("Found recipient from custom stablecoin event: $recipientAddress");
            break;
        }
    }

    // Method 2: Extract from payload arguments (works for USDT and standard transfers)
    if (!$recipientAddress && isset($message['payload'])) {
        $payload = $message['payload'];
        
        // Check if this is a primary_fungible_store::transfer
        if (isset($payload['function']) && 
            $payload['function'] === '0x1::primary_fungible_store::transfer') {
            
            // Get the coin type from type_arguments
            $typeArgs = $payload['type_arguments'] ?? [];
            if (!empty($typeArgs) && $typeArgs[0] === '0x1::fungible_asset::Metadata') {
                // Get the actual coin type from arguments
                $args = $payload['arguments'] ?? [];
                if (isset($args[0]['inner'])) {
                    $coinType = $args[0]['inner'];
                    
                    // Identify currency from coin type
                    if (isset($COIN_TYPES[$coinType])) {
                        $currency = $COIN_TYPES[$coinType];
                        logMessage("Detected currency: $currency from coin type: $coinType");
                    }
                    
                    // Get recipient address (second argument)
                    if (isset($args[1])) {
                        $recipientAddress = $args[1];
                        logMessage("Found recipient from payload arguments: $recipientAddress");
                    }
                }
            }
        }

        if (!$recipientAddress && ($payload['type'] ?? '') === 'script_payload') {
            $args = $payload['arguments'] ?? [];
            if (isset($args[0]['inner'], $args[4]) && is_string($args[4]) && strpos($args[4], '0x') === 0) {
                $coinType = $args[0]['inner'];
                if (isset($COIN_TYPES[$coinType])) {
                    $currency = $COIN_TYPES[$coinType];
                }
                $recipientAddress = $args[4];
                logMessage("Found recipient from script_payload: $recipientAddress ($currency)");
            }
        }

        if (!$recipientAddress && ($payload['type'] ?? '') === 'entry_function_payload') {
            $args = $payload['arguments'] ?? [];
            if (isset($args[0]['inner'], $args[1]) && is_string($args[1]) && strpos($args[1], '0x') === 0) {
                $coinType = $args[0]['inner'];
                if (isset($COIN_TYPES[$coinType])) {
                    $currency = $COIN_TYPES[$coinType];
                    $recipientAddress = $args[1];
                    logMessage("Found recipient from FA mint-like entry args: $recipientAddress ($currency)");
                }
            }
        }
    }

    if (!$recipientAddress) {
        logMessage("No recipient address found in deposit event");
        return;
    }

    logMessage("Recipient address: $recipientAddress");

    // Normalize address
    $normalizedAddress = normalizeAddress($recipientAddress);
    logMessage("Normalized address: $normalizedAddress");

    // Check if this address exists in our database
    $user = getUserByAddress($normalizedAddress);

    if (!$user) {
        logMessage("Address not found in database: $normalizedAddress");
        return;
    }

    logMessage("User found: ID={$user['Id']}, Phone={$user['phone_number']}");

    // Get transaction details
    $txHash = $message['hash'] ?? 'unknown';
    $sender = $message['sender'] ?? 'unknown';
    $timestamp = isset($message['timestamp']) ? 
        date('Y-m-d H:i:s', intval($message['timestamp']) / 1000000) : 
        'unknown';
    $version = $message['version'] ?? 'unknown';

    // Convert amount (assuming 6 decimals for both USDC and USDT)
    $amountInCurrency = $amount / 1000000;

    logMessage("Deposit details: Amount=$amountInCurrency $currency, From=$sender, TxHash=$txHash");

    // Send notification to WhatsApp webhook
    sendDepositNotification([
        'phone_number' => $user['phone_number'],
        'wallet_address' => $normalizedAddress,
        'amount' => $amountInCurrency,
        'currency' => $currency,
        'network' => $protocol,
        'sender_address' => $sender,
        'transaction_hash' => $txHash,
        'transaction_version' => $version,
        'timestamp' => $timestamp,
        'explorer_url' => "https://explorer.aptoslabs.com/txn/{$txHash}?network=" . strtolower((string) $network)
    ]);
}

// --- Aptos address forms: DB often stores short `0x`+hex; Nodit may use left-padded 64 nibbles ---
function aptosAddressLookupCandidates($address) {
    $hex = strtolower(preg_replace('/^0x/i', '', trim((string) $address)));
    if ($hex === '' || !ctype_xdigit($hex)) {
        return array_values(array_unique([$address]));
    }
    $padded = str_pad($hex, 64, '0', STR_PAD_LEFT);
    $short  = '0x' . (ltrim($padded, '0') ?: '0');
    return array_values(array_unique([
        strtolower(trim((string) $address)),
        '0x' . $hex,
        $short,
        '0x' . $padded,
    ]));
}

// --- Get User by Aptos Address ---
function getUserByAddress($address) {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

    try {
        $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $candidates = array_unique(array_merge(
            aptosAddressLookupCandidates($address),
            aptosAddressLookupCandidates(normalizeAddress($address))
        ));

        foreach ($candidates as $candidate) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE aptos_address = :address LIMIT 1");
            $stmt->execute(['address' => $candidate]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user;
            }
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(aptos_address) = LOWER(:address) LIMIT 1");
            $stmt->execute(['address' => $candidate]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user;
            }
        }

        return null;
    } catch (PDOException $e) {
        logMessage("Database error: " . $e->getMessage());
        return null;
    }
}

// --- Send Deposit Notification ---
function sendDepositNotification($data) {
    global $WHATSAPP_WEBHOOK_URL;

    logMessage("=== Sending Deposit Notification ===");
    logMessage("Phone: {$data['phone_number']}, Amount: {$data['amount']} {$data['currency']}");

    // Prepare the notification data
    $payload = [
        'phone_number' => $data['phone_number'],
        'wallet_address' => $data['wallet_address'],
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'network' => $data['network'],
        'sender_address' => $data['sender_address'],
        'transaction_hash' => $data['transaction_hash'],
        'transaction_version' => $data['transaction_version'],
        'timestamp' => $data['timestamp'],
        'explorer_url' => $data['explorer_url']
    ];

    $jsonPayload = json_encode($payload);
    logMessage("Payload: $jsonPayload");

    // Send POST request to WhatsApp webhook
    $ch = curl_init($WHATSAPP_WEBHOOK_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    $headers = WebhookAuthService::internalAuthCurlHeaders();
    $headers[] = 'Content-Length: ' . strlen($jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        logMessage("cURL error: " . curl_error($ch));
    } else {
        logMessage("WhatsApp webhook response: HTTP $httpCode - $response");
    }

    curl_close($ch);

    return $response;
}

// --- Normalize Address ---
function normalizeAddress($address) {
    // Remove 0x prefix if present
    $addr = strtolower($address);
    if (strpos($addr, '0x') === 0) {
        $addr = substr($addr, 2);
    }

    // Pad with leading zeros to 64 characters
    $addr = str_pad($addr, 64, '0', STR_PAD_LEFT);

    return '0x' . $addr;
}