<?php
// aptos_indexer_webhook.php - Custom Aptos Indexer Webhook System
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';

use App\Services\AptosLabsClient;
use App\Services\NoditIndexerDepositService;
use App\Services\WebhookAuthService;

$DB_HOST = $_ENV['APTOS_DB_HOST'] ?? getenv('APTOS_DB_HOST') ?: 'localhost';
$DB_NAME = $_ENV['APTOS_DB_NAME'] ?? getenv('APTOS_DB_NAME') ?: 'sendra_db';
$DB_USER = $_ENV['APTOS_DB_USER'] ?? getenv('APTOS_DB_USER') ?: 'developer';
$DB_PASS = $_ENV['APTOS_DB_PASS'] ?? getenv('APTOS_DB_PASS') ?: '';

$aptosLabs = new AptosLabsClient();
$noditIndexer = new NoditIndexerDepositService();
$USE_NODIT_INDEXER = $noditIndexer->isConfigured()
    && strtolower(trim((string) ($_ENV['NODIT_USE_INDEXER'] ?? getenv('NODIT_USE_INDEXER') ?? '1'))) !== '0';

$APTOS_GRAPHQL_ENDPOINT = $USE_NODIT_INDEXER
    ? $noditIndexer->getGraphqlUrl()
    : $aptosLabs->getGraphqlUrl();
$publicBase = rtrim((string) ($_ENV['SENDRA_PUBLIC_BASE_URL'] ?? getenv('SENDRA_PUBLIC_BASE_URL') ?: 'https://usesendra.com'), '/');
$WHATSAPP_WEBHOOK_URL = $publicBase . '/webhook.php?action=deposit';
$LOG_FILE = '/var/www/sendra/app/var/log/aptos-indexer-webhook.log';

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

/** @return list<string> */
function getSendraWalletAddresses() {
    $pdo = getDB();
    if (!$pdo) {
        return [];
    }
    $stmt = $pdo->query(
        "SELECT aptos_address FROM users WHERE aptos_address IS NOT NULL AND aptos_address != ''"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    return is_array($rows) ? $rows : [];
}

/** Wallets with open/recent buy — small set for Nodit lookback (avoids GraphQL time limits). */
function getOnrampLookbackWalletAddresses(): array
{
    $pdo = getDB();
    if (!$pdo) {
        return [];
    }

    $addrs = [];
    $stmt = $pdo->query(
        "SELECT DISTINCT u.aptos_address
         FROM pending_transactions p
         INNER JOIN users u ON u.phone_number = p.user_phone
         WHERE p.tx_type IN ('onramp_oneclick_centiiv', 'onramp_daya_aptos')
           AND p.created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)
           AND u.aptos_address IS NOT NULL AND u.aptos_address != ''"
    );
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $a) {
            if (is_string($a) && $a !== '') {
                $addrs[] = $a;
            }
        }
    }

    return array_values(array_unique($addrs));
}

/** Remove only versions clearly above chain head (no per-row REST calls). */
function purgeInvalidTrackerVersions(): int
{
    $pdo = getDB();
    if (!$pdo) {
        return 0;
    }

    $ledgerHead = aptosMainnetLedgerVersion();
    if ($ledgerHead === null) {
        return 0;
    }

    $cutoff = $ledgerHead + 1000;
    $stmt = $pdo->prepare('DELETE FROM aptos_webhook_tracker WHERE transaction_version > ?');
    $stmt->execute([$cutoff]);
    $removed = (int) $stmt->rowCount();
    if ($removed > 0) {
        logMessage("Purged {$removed} tracker row(s) above ledger head {$ledgerHead}");
    }

    return $removed;
}

function maxValidTrackedVersion(): int
{
    $pdo = getDB();
    if (!$pdo) {
        return 0;
    }

    $ledgerHead = aptosMainnetLedgerVersion();
    if ($ledgerHead === null) {
        $stmt = $pdo->query('SELECT COALESCE(MAX(transaction_version), 0) FROM aptos_webhook_tracker');

        return (int) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(transaction_version), 0) FROM aptos_webhook_tracker WHERE transaction_version <= ?'
    );
    $stmt->execute([$ledgerHead]);

    return (int) $stmt->fetchColumn();
}

function queryAptosIndexer($lastProcessedVersion = 0) {
    global $USE_NODIT_INDEXER, $noditIndexer, $aptosLabs;

    logMessage('Querying indexer from version ' . $lastProcessedVersion
        . ($USE_NODIT_INDEXER
            ? ' (Nodit GraphQL, Sendra wallets only)'
            : ' (Aptos Labs GraphQL' . ($aptosLabs->hasApiKey() ? ', Geomi API key' : ', anonymous — set APTOS_NODE_API_KEY') . ', Sendra wallets only)'));

    if ($USE_NODIT_INDEXER) {
        $owners = getSendraWalletAddresses();
        if ($owners === []) {
            logMessage('No Sendra wallet addresses in DB');

            return [];
        }
        $activities = $noditIndexer->fetchDepositsForOwners($owners, (int) $lastProcessedVersion, 100);
        if ($activities === null) {
            logMessage('Nodit indexer error: ' . ($noditIndexer->getLastError() ?? 'unknown'));

            return null;
        }

        $lookbackSec = (int) ($_ENV['NODIT_INDEXER_LOOKBACK_SECONDS'] ?? getenv('NODIT_INDEXER_LOOKBACK_SECONDS') ?: 86400);
        if ($lookbackSec > 0 && $lastProcessedVersion > 0) {
            $lookbackOwners = getOnrampLookbackWalletAddresses();
            if ($lookbackOwners === []) {
                $lookbackOwners = $owners;
            }
            $missed = $noditIndexer->fetchDepositsBelowCursorRecent($lookbackOwners, (int) $lastProcessedVersion, $lookbackSec, 30);
            if ($missed === null) {
                logMessage('Nodit lookback error: ' . ($noditIndexer->getLastError() ?? 'unknown'));
            } elseif ($missed !== []) {
                logMessage('Lookback found ' . count($missed) . ' deposit(s) below cursor ' . $lastProcessedVersion);
                $seen = [];
                foreach ($activities as $a) {
                    $seen[(string) ($a['transaction_version'] ?? '')] = true;
                }
                foreach ($missed as $m) {
                    $v = (string) ($m['transaction_version'] ?? '');
                    if ($v !== '' && !isset($seen[$v])) {
                        $activities[] = $m;
                        $seen[$v] = true;
                    }
                }
                usort($activities, fn ($a, $b) => (int) $a['transaction_version'] <=> (int) $b['transaction_version']);
            }
        }

        return $activities;
    }

    global $aptosLabs, $TOKEN_METADATA;

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

    // Filter by Sendra wallet addresses from DB (avoids chain-wide scanning).
    $owners = getSendraWalletAddresses();
    if ($owners === []) {
        logMessage('No Sendra wallet addresses in DB');
        return [];
    }
    $owners = array_values(array_unique(array_filter($owners)));

    $assetTypes = array_keys($TOKEN_METADATA);
    $baseWhere = [
        'asset_type' => ['_in' => $assetTypes],
        'type' => ['_eq' => '0x1::fungible_asset::Deposit'],
        'is_transaction_success' => ['_eq' => true],
        'transaction_version' => ['_gt' => (string) $lastProcessedVersion],
    ];

    // Chunk owner list to keep the GraphQL payload reasonable.
    // Safety: GraphQL uses `limit: 100` ordered asc. If a chunk has >100 results,
    // it truncates at some max version. To avoid skipping deposits in other chunks,
    // we compute a "safe cursor" as the minimum maxVersion returned across chunks,
    // then only keep activities with transaction_version <= safeCursor.
    $ownerChunkSize = 80;
    $allActivities = [];
    $chunkMaxVersions = [];

    foreach (array_chunk($owners, $ownerChunkSize) as $ownerChunk) {
        $variables = [
            'where' => array_merge($baseWhere, [
                'owner_address' => ['_in' => array_values($ownerChunk)],
            ]),
        ];

        $result = $aptosLabs->graphql($query, $variables, 30);
        if ($result['curl_error'] !== null) {
            logMessage('cURL Error: ' . $result['curl_error']);

            return null;
        }

        $httpCode = $result['http_code'];
        $response = $result['body'];
        if ($httpCode !== 200) {
            logMessage("HTTP Error: $httpCode - Response: $response");

            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['data']['fungible_asset_activities'])) {
            logMessage('Invalid response structure: ' . $response);

            return null;
        }

        $chunkActivities = $data['data']['fungible_asset_activities'];
        if (!is_array($chunkActivities) || $chunkActivities === []) {
            continue;
        }

        $chunkMax = 0;
        foreach ($chunkActivities as $a) {
            if (!isset($a['transaction_version'])) continue;
            $v = (int) $a['transaction_version'];
            if ($v > $chunkMax) $chunkMax = $v;
        }
        if ($chunkMax > 0) {
            $chunkMaxVersions[] = $chunkMax;
        }

        $allActivities = array_merge($allActivities, $chunkActivities);
    }

    if ($allActivities === []) {
        return [];
    }

    $safeCursor = (int) min($chunkMaxVersions ?: [(int) $lastProcessedVersion]);

    $filtered = array_values(array_filter($allActivities, function ($a) use ($safeCursor) {
        if (!isset($a['transaction_version'])) return false;
        return ((int) $a['transaction_version']) <= $safeCursor;
    }));

    usort($filtered, fn ($a, $b) => (int) $a['transaction_version'] <=> (int) $b['transaction_version']);

    return enrichActivitiesWithSenderAndHash($filtered);
}

/**
 * fungible_asset_activities has no sender/hash. Attach both:
 *   - sender via GraphQL user_transactions (by version)
 *   - transaction_hash via REST /transactions/by_version
 *
 * @param list<array<string, mixed>> $activities
 * @return list<array<string, mixed>>
 */
function enrichActivitiesWithSenderAndHash(array $activities) {
    global $aptosLabs;

    if ($activities === []) {
        return $activities;
    }

    $versions = array_values(array_unique(array_filter(array_map(
        fn ($a) => (string) ($a['transaction_version'] ?? ''),
        $activities
    ))));
    if ($versions === []) {
        return $activities;
    }

    // Senders: GraphQL user_transactions (batched)
    $senders = [];
    $senderQuery = <<<'GRAPHQL'
query TxSenders($versions: [bigint!]!) {
  user_transactions(where: { version: { _in: $versions } }) {
    version
    sender
  }
}
GRAPHQL;

    foreach (array_chunk($versions, 50) as $chunk) {
        $result = $aptosLabs->graphql($senderQuery, ['versions' => array_map('intval', $chunk)], 30);
        if ($result['http_code'] !== 200) {
            continue;
        }
        $data = json_decode($result['body'], true);
        foreach ($data['data']['user_transactions'] ?? [] as $tx) {
            $v = (string) ($tx['version'] ?? '');
            $s = $tx['sender'] ?? '';
            if ($v !== '' && is_string($s) && $s !== '') {
                $senders[$v] = $s;
            }
        }
    }

    // Hashes: REST by version
    $hashes = [];
    foreach ($versions as $version) {
        $result = $aptosLabs->restGet("transactions/by_version/{$version}", 10);
        $tx = json_decode($result['body'], true);
        if (is_array($tx) && !empty($tx['hash'])) {
            $hashes[$version] = (string) $tx['hash'];
        }
    }

    foreach ($activities as &$row) {
        $v = (string) ($row['transaction_version'] ?? '');
        if (isset($senders[$v])) {
            $row['sender'] = $senders[$v];
        }
        if (isset($hashes[$v])) {
            $row['transaction_hash'] = $hashes[$v];
        }
    }
    unset($row);

    return $activities;
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

function aptosMainnetLedgerVersion(): ?int
{
    global $aptosLabs;

    $net = explorerNetworkParam();
    if ($net !== 'mainnet') {
        return null;
    }

    $result = $aptosLabs->restGet('', 10);
    $data = json_decode($result['body'], true);
    if (!is_array($data) || empty($data['ledger_version'])) {
        return null;
    }

    return (int) $data['ledger_version'];
}

function aptosTxVersionExistsOnChain(int $version): bool
{
    global $aptosLabs;

    if ($version <= 0) {
        return false;
    }

    $result = $aptosLabs->restGet("transactions/by_version/{$version}", 8);
    $code = $result['http_code'];

    if ($code === 429 || $code === 503 || $code === 0) {
        return true;
    }

    if ($code !== 200) {
        return false;
    }

    $data = json_decode($result['body'], true);

    return is_array($data) && isset($data['version']);
}

// Get last processed version (reconcile if cursor ran ahead of chain / missed low versions)
function getLastProcessedVersion() {
    $pdo = getDB();
    if (!$pdo) return 0;

    purgeInvalidTrackerVersions();
    
    $stmt = $pdo->query("SELECT last_processed_version FROM aptos_webhook_settings WHERE id = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stored = $result ? (int)$result['last_processed_version'] : 0;

    $ledgerHead = aptosMainnetLedgerVersion();
    $maxValid = maxValidTrackedVersion();

    if ($ledgerHead !== null && $stored > $ledgerHead + 1000) {
        $reconciled = $maxValid > 0 ? min($maxValid, $ledgerHead) : max(0, $ledgerHead - 500000);
        logMessage("Reconciling cursor {$stored} down to {$reconciled} (ledger head {$ledgerHead}, max valid tracked {$maxValid})");
        updateLastProcessedVersion($reconciled);

        return $reconciled;
    }

    if ($stored > 0 && !aptosTxVersionExistsOnChain($stored)) {
        $fallback = $maxValid > 0 ? $maxValid : ($ledgerHead ?? 0);
        logMessage("Cursor {$stored} not on chain; fallback to {$fallback}");
        updateLastProcessedVersion($fallback);

        return $fallback;
    }

    if ($stored === 0 && $ledgerHead !== null && trackerRowCount() === 0) {
        logMessage("Empty tracker; seeding cursor to ledger head {$ledgerHead}");
        updateLastProcessedVersion($ledgerHead);

        return $ledgerHead;
    }

    if ($maxValid > 0 && $stored > $maxValid + 50000) {
        logMessage("Cursor {$stored} ahead of max valid tracked {$maxValid}; aligning");
        updateLastProcessedVersion($maxValid);

        return $maxValid;
    }

    return $stored;
}

// Update last processed version
function updateLastProcessedVersion($version) {
    $pdo = getDB();
    if (!$pdo) return;

    $version = (int) $version;

    $ledgerHead = aptosMainnetLedgerVersion();
    if ($ledgerHead !== null && $version > $ledgerHead) {
        $version = $ledgerHead;
    }
    
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

/** Microseconds for aptos_webhook_tracker.transaction_timestamp (bigint). */
function indexerTimestampMicros($raw): int
{
    if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
        $t = strtotime($raw);

        return $t ? $t * 1000000 : (int) (microtime(true) * 1000000);
    }
    $micros = (string) $raw;
    if ($micros !== '' && ctype_digit($micros)) {
        return (int) $micros;
    }

    return (int) (microtime(true) * 1000000);
}

function explorerNetworkParam(): string
{
    $raw = $_ENV['APTOS_EXPLORER_NETWORK'] ?? getenv('APTOS_EXPLORER_NETWORK') ?: 'testnet';
    $net = strtolower(trim((string) $raw));

    return $net === 'mainnet' ? 'mainnet' : 'testnet';
}

function trackerRowCount(): int
{
    $pdo = getDB();
    if (!$pdo) {
        return 0;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM aptos_webhook_tracker')->fetchColumn();
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
            'timestamp' => indexerTimestampMicros($activity['transaction_timestamp'] ?? ''),
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

    $version = (string) ($activity['transaction_version'] ?? '');
    $txHash = (string) ($activity['transaction_hash'] ?? '');
    $sender = (string) ($activity['sender'] ?? 'unknown');
    $net = explorerNetworkParam();
    $explorerUrl = $txHash !== ''
        ? "https://explorer.aptoslabs.com/txn/{$txHash}?network={$net}"
        : ($version !== '' ? "https://explorer.aptoslabs.com/txn/{$version}?network={$net}" : '');

    $payload = [
        'phone_number' => $user['phone_number'],
        'wallet_address' => $activity['owner_address'],
        'amount' => $amount,
        'currency' => $currency,
        'network' => 'aptos',
        'sender_address' => $sender,
        'transaction_hash' => $txHash !== '' ? $txHash : $version,
        'transaction_version' => $version,
        'timestamp' => date('Y-m-d H:i:s', (int) floor(indexerTimestampMicros($activity['transaction_timestamp'] ?? '') / 1000000)),
        'explorer_url' => $explorerUrl,
    ];
    
    $jsonPayload = json_encode($payload);
    
    logMessage("Sending notification: Phone={$user['phone_number']}, Amount=$amount $currency");
    
    $ch = curl_init($WHATSAPP_WEBHOOK_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, WebhookAuthService::internalAuthCurlHeaders());
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
    
    $silentCatchup = trackerRowCount() === 0
        && strtolower(trim((string) ($_ENV['NODIT_INDEXER_SILENT_CATCHUP'] ?? getenv('NODIT_INDEXER_SILENT_CATCHUP') ?: '1'))) !== '0';

    if ($silentCatchup) {
        logMessage('First run: marking existing deposits in tracker without WhatsApp (NODIT_INDEXER_SILENT_CATCHUP=1)');
    }

    foreach ($activities as $activity) {
        $version = (int) $activity['transaction_version'];

        if ($version > $maxVersion) {
            $maxVersion = $version;
        }

        if (isTransactionProcessed($version)) {
            continue;
        }

        $currency = $TOKEN_METADATA[$activity['asset_type']] ?? 'UNKNOWN';
        if ($currency === 'UNKNOWN') {
            saveTransaction($activity, $currency, false);
            continue;
        }

        $normalizedAddress = normalizeAddress($activity['owner_address']);
        $user = getUserByAddress($normalizedAddress);
        if (!$user) {
            saveTransaction($activity, $currency, false);
            continue;
        }

        if ($silentCatchup) {
            saveTransaction($activity, $currency, true);
            $processedCount++;
            continue;
        }

        logMessage("Match: user {$user['Id']}, {$activity['amount']} $currency, v$version");
        $notified = sendNotification($user, $activity, $currency);
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

