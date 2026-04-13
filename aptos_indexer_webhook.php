<?php
// aptos_indexer_webhook.php - Custom Aptos Indexer Webhook System
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';

$DB_HOST = $_ENV['APTOS_DB_HOST'] ?? getenv('APTOS_DB_HOST') ?: 'localhost';
$DB_NAME = $_ENV['APTOS_DB_NAME'] ?? getenv('APTOS_DB_NAME') ?: 'sendra_db';
$DB_USER = $_ENV['APTOS_DB_USER'] ?? getenv('APTOS_DB_USER') ?: 'developer';
$DB_PASS = $_ENV['APTOS_DB_PASS'] ?? getenv('APTOS_DB_PASS') ?: '';

$APTOS_GRAPHQL_ENDPOINT = 'https://api.testnet.aptoslabs.com/v1/graphql'; // Change to mainnet for production
$WHATSAPP_WEBHOOK_URL = 'https://coinectra.stipendly.app/webhook.php?action=deposit';
$LOG_FILE = '/var/www/html/coinectra/aptos-indexer-webhook.log';

// Token metadata addresses
$TOKEN_METADATA = [
    // Mainnet
    '0xbae207659db88bea0cbead6da0ed00aac12edcdda169e591cd41c94180b46f3b' => 'USDC',
    '0x357b0b74bc833e95a115ad22604854d6b0fca151cecd94111770e5d6ffc9dc2b' => 'USDT',
    // Testnet
    '0x69091fbab5f7d635ee7ac5098cf0c1efbe31d68fec0f2cd565e8d168daf52832' => 'USDC',
    '0x5724aca06f8bd505e9eaf2337d16d6fc310c96ecfd331269bf2f76840d3f355a' => 'USDT'
];

// Log function
function logMessage($message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

// GraphQL Query
function queryAptosIndexer($lastProcessedVersion = 0) {
    global $APTOS_GRAPHQL_ENDPOINT, $TOKEN_METADATA;
    
    $query = <<<'GRAPHQL'
query GetFungibleAssetActivities($where: fungible_asset_activities_bool_exp) {
  fungible_asset_activities(
    limit: 100
    order_by: {transaction_version: asc}
    where: $where
  ) {
    amount
    asset_type
    transaction_version
    transaction_timestamp
    owner_address
    type
    is_transaction_success
    entry_function_id_str
  }
}
GRAPHQL;

    $assetTypes = array_keys($TOKEN_METADATA);
    
    $variables = [
        'where' => [
            'asset_type' => ['_in' => $assetTypes],
            'type' => ['_eq' => '0x1::fungible_asset::Deposit'],
            'is_transaction_success' => ['_eq' => true],
            'transaction_version' => ['_gt' => (string)$lastProcessedVersion]
        ]
    ];
    
    $payload = json_encode([
        'query' => $query,
        'variables' => $variables
    ]);
    
    logMessage("Querying Aptos Indexer from version: $lastProcessedVersion");
    
    $ch = curl_init($APTOS_GRAPHQL_ENDPOINT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        logMessage("cURL Error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        logMessage("HTTP Error: $httpCode - Response: $response");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['data']['fungible_asset_activities'])) {
        logMessage("Invalid response structure: " . $response);
        return null;
    }
    
    return $data['data']['fungible_asset_activities'];
}

// Get database connection
function getDB() {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    
    try {
        $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        logMessage("Database error: " . $e->getMessage());
        return null;
    }
}

// Get last processed version
function getLastProcessedVersion() {
    $pdo = getDB();
    if (!$pdo) return 0;
    
    $stmt = $pdo->query("SELECT last_processed_version FROM aptos_webhook_settings WHERE id = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? (int)$result['last_processed_version'] : 0;
}

// Update last processed version
function updateLastProcessedVersion($version) {
    $pdo = getDB();
    if (!$pdo) return;
    
    $stmt = $pdo->prepare("UPDATE aptos_webhook_settings SET last_processed_version = :version WHERE id = 1");
    $stmt->execute(['version' => $version]);
    
    logMessage("Updated last processed version to: $version");
}

// Check if user exists in database
function getUserByAddress($address) {
    $pdo = getDB();
    if (!$pdo) return null;
    
    $normalizedAddress = normalizeAddress($address);
    
    // Try exact match
    $stmt = $pdo->prepare("SELECT * FROM users WHERE aptos_address = :address LIMIT 1");
    $stmt->execute(['address' => $normalizedAddress]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If no exact match, try case-insensitive
    if (!$user) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(aptos_address) = LOWER(:address) LIMIT 1");
        $stmt->execute(['address' => $normalizedAddress]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $user;
}

// Normalize address
function normalizeAddress($address) {
    $addr = strtolower($address);
    if (strpos($addr, '0x') === 0) {
        $addr = substr($addr, 2);
    }
    $addr = str_pad($addr, 64, '0', STR_PAD_LEFT);
    return '0x' . $addr;
}

// Check if transaction already processed
function isTransactionProcessed($version) {
    $pdo = getDB();
    if (!$pdo) return false;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM aptos_webhook_tracker WHERE transaction_version = :version");
    $stmt->execute(['version' => $version]);
    
    return $stmt->fetchColumn() > 0;
}

// Save processed transaction
function saveTransaction($activity, $currency, $notified = false) {
    $pdo = getDB();
    if (!$pdo) return false;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO aptos_webhook_tracker 
            (transaction_version, transaction_timestamp, owner_address, amount, asset_type, currency, notified)
            VALUES (:version, :timestamp, :owner, :amount, :asset, :currency, :notified)
        ");
        
        $stmt->execute([
            'version' => $activity['transaction_version'],
            'timestamp' => $activity['transaction_timestamp'],
            'owner' => $activity['owner_address'],
            'amount' => $activity['amount'],
            'asset' => $activity['asset_type'],
            'currency' => $currency,
            'notified' => $notified ? 1 : 0
        ]);
        
        return true;
    } catch (PDOException $e) {
        logMessage("Error saving transaction: " . $e->getMessage());
        return false;
    }
}

// Send notification
function sendNotification($user, $activity, $currency) {
    global $WHATSAPP_WEBHOOK_URL;
    
    $decimals = 6; // USDC/USDT use 6 decimals
    $amount = $activity['amount'] / pow(10, $decimals);
    
    $timestamp = date('Y-m-d H:i:s', intval($activity['transaction_timestamp']) / 1000000);
    
    $payload = [
        'phone_number' => $user['phone_number'],
        'wallet_address' => $activity['owner_address'],
        'amount' => $amount,
        'currency' => $currency,
        'network' => 'aptos',
        'transaction_version' => $activity['transaction_version'],
        'timestamp' => $timestamp,
        'explorer_url' => "https://explorer.aptoslabs.com/txn/{$activity['transaction_version']}?network=testnet"
    ];
    
    $jsonPayload = json_encode($payload);
    
    logMessage("Sending notification: Phone={$user['phone_number']}, Amount=$amount $currency");
    
    $ch = curl_init($WHATSAPP_WEBHOOK_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        logMessage("Notification error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    logMessage("Notification sent: HTTP $httpCode");
    
    return $httpCode >= 200 && $httpCode < 300;
}

// Process activities
function processActivities($activities) {
    global $TOKEN_METADATA;
    
    if (empty($activities)) {
        logMessage("No new activities found");
        return 0;
    }
    
    logMessage("Processing " . count($activities) . " activities");
    
    $processedCount = 0;
    $maxVersion = 0;
    
    foreach ($activities as $activity) {
        $version = (int)$activity['transaction_version'];
        
        // Track max version
        if ($version > $maxVersion) {
            $maxVersion = $version;
        }
        
        // Skip if already processed
        if (isTransactionProcessed($version)) {
            logMessage("Transaction $version already processed, skipping");
            continue;
        }
        
        // Get currency from metadata
        $currency = $TOKEN_METADATA[$activity['asset_type']] ?? 'UNKNOWN';
        
        if ($currency === 'UNKNOWN') {
            logMessage("Unknown asset type: {$activity['asset_type']}");
            saveTransaction($activity, $currency, false);
            continue;
        }
        
        // Normalize and check if user exists
        $normalizedAddress = normalizeAddress($activity['owner_address']);
        $user = getUserByAddress($normalizedAddress);
        
        if (!$user) {
            logMessage("Address not in database: $normalizedAddress");
            saveTransaction($activity, $currency, false);
            continue;
        }
        
        logMessage("Match found! User ID: {$user['Id']}, Amount: {$activity['amount']}, Currency: $currency");
        
        // Send notification
        $notified = sendNotification($user, $activity, $currency);
        
        // Save transaction
        saveTransaction($activity, $currency, $notified);
        
        $processedCount++;
    }
    
    // Update last processed version
    if ($maxVersion > 0) {
        updateLastProcessedVersion($maxVersion);
    }
    
    return $processedCount;
}

// Main execution
function main() {
    logMessage("=== Aptos Indexer Webhook Started ===");
    
    $lastVersion = getLastProcessedVersion();
    logMessage("Last processed version: $lastVersion");
    
    $activities = queryAptosIndexer($lastVersion);
    
    if ($activities === null) {
        logMessage("Failed to query Aptos Indexer");
        return;
    }
    
    $count = processActivities($activities);
    
    logMessage("=== Processed $count new deposits ===");
}

// Run the script
main();

