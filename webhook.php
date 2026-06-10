<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); // FIXED: was 1 — PHP errors in response body break WhatsApp's 200 check
ini_set('log_errors', 1);
//ini_set('error_log', '/var/www/html/php-debug.log');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Internal-Secret');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';
require __DIR__ . '/config/Database.php';
$botConfigs = require __DIR__ . '/config/Bots.php';

use App\Controllers\AuthController;
use App\Controllers\LogisticsAuthController;
use App\Controllers\MessageController;
use App\Services\SendraIndexerDepositNotifyService;
use App\Services\WebhookAuthService;
use App\Services\WhatsAppInboundFilter;
use App\Services\WhatsAppService;

$VERIFY_TOKEN = $_ENV['WHATSAPP_VERIFY_TOKEN'] ?? getenv('WHATSAPP_VERIFY_TOKEN') ?: '';
$ACCESS_TOKEN = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$SENDRA_APTOS_PHONE_ID = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834';
$SENDRA_LOGISTICS_PHONE_ID = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';

// Read POST body once: php://input is not reliably re-readable; deposit JSON has no `entry` tree.
$postRawBody = '';
$postJson    = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postRawBody = file_get_contents('php://input') ?: '';
    $postJson    = json_decode($postRawBody, true) ?: [];
}
$incomingPhoneNumberId = $SENDRA_APTOS_PHONE_ID;
if (!empty($postJson['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'])) {
    $incomingPhoneNumberId = $postJson['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'];
}

$bot = $botConfigs[$incomingPhoneNumberId] ?? null;

if (!$bot) {
    exit("Unknown bot");
}

$botConfig = $botConfigs[$incomingPhoneNumberId] ?? $botConfigs[$SENDRA_APTOS_PHONE_ID];

$db = Database::getInstance($botConfig['db_config'], useReplica:false)->getConnection();
$whatsappService = new WhatsAppService($ACCESS_TOKEN, $incomingPhoneNumberId);

$authController  = new AuthController($db, $whatsappService);
$controllerClass = "App\\Controllers\\{$botConfig['controller']}";

// Separate auth + message controllers
if ($incomingPhoneNumberId === $SENDRA_LOGISTICS_PHONE_ID) {
    // Logistics bot
    $authController = new LogisticsAuthController($db, $whatsappService);
    $messageControllerClass = "App\\Controllers\\LogisticsMessageController";
} else {
    // Default Aptos bot
    $authController = new AuthController($db, $whatsappService);
    $messageControllerClass = "App\\Controllers\\{$botConfig['controller']}";
}

// FIXED: Old code: new MessageController($whatsappService, $db)
//        New MessageController constructor requires AuthController as second param, not $db
$messageController = new $controllerClass($whatsappService, $authController, $db);

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── GET: Webhook verification ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? null;
    $token     = $_GET['hub_verify_token'] ?? null;
    $challenge = $_GET['hub_challenge']    ?? null;

    if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo "Forbidden";
    }
    exit;
}

// ── POST: All incoming requests ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body       = $postRawBody;
    $requestUri = $_SERVER['REQUEST_URI'];
    $action     = (string) ($_GET['action'] ?? '');

    error_log('=== WEBHOOK HIT: ' . $requestUri . ' action=' . $action . ' ===');

    $isInternalRoute = WebhookAuthService::isInternalPostRoute($requestUri, $action);
    if ($isInternalRoute) {
        WebhookAuthService::requireInternalAuthOrExit();
    } else {
        WebhookAuthService::requireMetaSignatureOrExit($body);
    }

    // ── /signup ───────────────────────────────────────────────────────────────
    if (strpos($requestUri, '/signup') !== false || $action === 'signup') {
        $data = json_decode($body, true);

        if (!isset($data['phoneNumber'], $data['username'], $data['aptos_address'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
        }

        // FIXED: Old code called signup($phone, $username, $address) — 3 params.
        // New signup() needs 5 params. Wallet adapter users are always 'petra' type.
        $result = $authController->signup(
            $data['phoneNumber'],
            $data['username'],
            $data['aptos_address'],
            null,
            'petra'
        );

        sendJsonResponse($result, $result['success'] ? 200 : 400);
    }

    // ── /sendSuccess ──────────────────────────────────────────────────────────
    if (strpos($requestUri, '/sendSuccess') !== false || $action === 'sendSuccess') {
        $data = json_decode($body, true);

        if (!isset($data['amount'], $data['currency'], $data['recipient'], $data['sender'], $data['senderPhoneNumber'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
        }

        $message = "*Summary*:\nSent {$data['amount']} {$data['currency']} on *Aptos*\n" .
                   "From: {$data['sender']}\nTo: {$data['recipient']}\n\n" .
                   "*Transaction Hash*: {$data['transactionHash']}\n\n" .
                   "*View in Explorer*:\nhttps://explorer.aptoslabs.com/txn/{$data['transactionHash']}";

        $result = $whatsappService->sendMessage($data['senderPhoneNumber'], $message);
        sendJsonResponse($result, $result['success'] ? 200 : 400);
    }

    // ── /voucher/claim ────────────────────────────────────────────────────────
    // Called by the web app after recipient confirms their phone number
    // No aptos_address needed — backend generates a fresh Sendra keypair
    if (strpos($requestUri, '/voucher/claim') !== false || $action === 'voucherClaim') {
        $data = json_decode($body, true);

        if (!isset($data['token'], $data['phone_number'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing token or phone_number'], 400);
        }

        $result = $messageController->executeVoucherClaim(
            $data['token'],
            $data['phone_number'],
            $data['username'] ?? null   // ADD THIS
        );

        sendJsonResponse($result, $result['success'] ? 200 : 400);
    }

    // ── /deposit ──────────────────────────────────────────────────────────────
    // Matches both /deposit path and ?action=deposit query string (used by nodit.php)
    $isDepositRequest = strpos($requestUri, '/deposit') !== false
        || $action === 'deposit';

    if ($isDepositRequest) {
        error_log("=== DEPOSIT NOTIFICATION RECEIVED ===");
        $data = json_decode($body, true);
        handleDepositNotification($data, $whatsappService);
        // handleDepositNotification calls sendJsonResponse which exits
    }

    // FIXED: /sendTemplateBatch MUST be checked before /sendTemplate
    // because strpos('/sendTemplateBatch') also matches '/sendTemplate'
    // causing batch requests to be handled by the single-send branch

    // ── /sendTemplateBatch ────────────────────────────────────────────────────
    if (strpos($requestUri, '/sendTemplateBatch') !== false) {
        $data = json_decode($body, true);

        if (!isset($data['recipients'], $data['templateName']) || !is_array($data['recipients'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing recipients or templateName'], 400);
        }

        $results = []; $successCount = 0; $failCount = 0;
        foreach ($data['recipients'] as $phone) {
            $result = $whatsappService->sendTemplate($phone, $data['templateName'], $data['languageCode'] ?? 'en');
            $result['success'] ? $successCount++ : $failCount++;
            $results[] = ['phoneNumber' => $phone, 'success' => $result['success']];
            usleep(100000);
        }

        sendJsonResponse([
            'success' => true,
            'summary' => ['total' => count($data['recipients']), 'successful' => $successCount, 'failed' => $failCount],
            'results' => $results,
        ]);
    }

    // ── /sendTemplate ─────────────────────────────────────────────────────────
    if (strpos($requestUri, '/sendTemplate') !== false) {
        $data = json_decode($body, true);

        if (!isset($data['phoneNumber'], $data['templateName'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing phoneNumber or templateName'], 400);
        }

        $result = $whatsappService->sendTemplate($data['phoneNumber'], $data['templateName'], $data['languageCode'] ?? 'en');
        sendJsonResponse($result, $result['success'] ? 200 : 400);
    }

    // ── WhatsApp message webhook (Meta signature verified above) ────────────────
    $data = $postJson;

    if (!isset($data['entry'])) {
        error_log('WEBHOOK: POST without entry after Meta signature check');
        http_response_code(400);
        echo 'Bad Request';
        exit;
    }

    foreach ($data['entry'] as $entry) {
        foreach ($entry['changes'] as $change) {
            $value = $change['value'] ?? [];

            // FIXED: Old code crashed on status update payloads (delivered/read receipts)
            // because they don't have $msg['from'] — caused PHP notices and silent drops
            if (isset($value['statuses']) && !isset($value['messages'])) {
                continue;
            }

            // Meta echo webhooks (our outbound messages) — never treat as user commands
            if (isset($value['message_echoes']) && is_array($value['message_echoes'])) {
                continue;
            }

            if (!isset($value['messages'])) {
                continue;
            }

            foreach ($value['messages'] as $msg) {
                $from = $msg['from'] ?? null;
                if (!$from) continue;

                // Determine message payload
                if (isset($msg['text']['body'])) {
                    $rawBody = trim((string) $msg['text']['body']);
                    $messagePayload = strtolower($rawBody);
                    if (WhatsAppInboundFilter::isOutboundEchoMessage($msg, $messagePayload)) {
                        error_log('WEBHOOK: ignored outbound echo from=' . $from . ' body=' . substr($rawBody, 0, 80));
                        continue;
                    }
                } elseif (isset($msg['interactive']['button_reply']['id'])) {
                    $messagePayload = trim(strtolower($msg['interactive']['button_reply']['id']));
                } elseif (isset($msg['interactive']['list_reply']['id'])) {
                    $messagePayload = trim(strtolower($msg['interactive']['list_reply']['id']));
                } elseif ($msg['type'] === 'location') {
                    $loc = $msg['location'] ?? [];
                    $lat = $loc['latitude'] ?? null;
                    $lng = $loc['longitude'] ?? null;
                    $name = $loc['name'] ?? null;
                    $address = $loc['address'] ?? null;

                    // Preserve rich fields for later steps (drivers need a readable address).
                    // Steps should still accept legacy "lat,lng" strings for compatibility.
                    $messagePayload = json_encode([
                        'location' => [
                            'latitude'  => $lat,
                            'longitude' => $lng,
                            'name'      => $name,
                            'address'   => $address,
                        ],
                    ]);
                    error_log("Received location message: " . $messagePayload);
                } elseif ($msg['type'] === 'image') {
                    $imagePayload = ['image' => $msg['image']];
                    if (!empty($msg['image']['caption']) && is_string($msg['image']['caption'])) {
                        $imagePayload['caption'] = $msg['image']['caption'];
                    }
                    $messagePayload = json_encode($imagePayload);
                    error_log("Received image message: " . json_encode($msg['image']));
                } else {
                    $messagePayload = '';
                }

                $userInfo   = $whatsappService->extractUserInfo($data);
                $userResult = $authController->getUser($from);
                $user       = $userResult['user'] ?? null;

                $displayName = $userInfo['username'] ?? 'there';

                $messageController->handleIncomingMessage($from, $messagePayload, $user, $displayName);
            }
        }
    }

    http_response_code(200);
    echo "EVENT_RECEIVED";
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function handleDepositNotification($data, $whatsappService) {
    global $db; // PDO connection bootstrapped at top of webhook.php

    error_log("=== Processing Deposit Notification ===");
    error_log("Data: " . json_encode($data));

    $phoneNumber   = $data['phone_number']     ?? null;
    $amount        = $data['amount']           ?? 0;
    $currency      = $data['currency']         ?? 'USDC';
    $network       = $data['network']          ?? 'Aptos';
    $walletAddress = $data['wallet_address']   ?? 'unknown';
    $senderAddress = $data['sender_address']   ?? 'unknown';
    $txHash        = $data['transaction_hash'] ?? 'unknown';
    $explorerUrl   = $data['explorer_url']     ?? '';

    if (!$phoneNumber) {
        error_log("ERROR: No phone number in deposit notification");
        sendJsonResponse(['success' => false, 'message' => 'No phone number provided'], 400);
    }

    if (!$db) {
        error_log('DEPOSIT NOTIFY: no database connection');
        sendJsonResponse(['success' => false, 'message' => 'Database unavailable'], 500);
    }

    $result = SendraIndexerDepositNotifyService::handle($db, $whatsappService, $data);

    // ── Record deposit in transaction history ─────────────────────────────────
    if ($result['success'] && $db && $txHash !== 'unknown') {
        try {
            // Use INSERT ... ON DUPLICATE KEY UPDATE with a no-op update
            // so duplicate tx_hash from the sender's 'out' record doesn't block this 'in' record.
            // The unique key must be (tx_hash, direction) — see migration note below.
            $stmt = $db->prepare("
                INSERT INTO transaction_history
                    (id, user_phone, direction, amount, currency, status,
                     aptos_address, counterparty, tx_hash, explorer_url, created_at)
                VALUES
                    (:id, :user_phone, 'in', :amount, :currency, 'confirmed',
                     :aptos_address, :counterparty, :tx_hash, :explorer_url, NOW())
                ON DUPLICATE KEY UPDATE id = id
            ");
            $stmt->execute([
                'id'           => bin2hex(random_bytes(16)),
                'user_phone'   => $phoneNumber,
                'amount'       => $amount,
                'currency'     => $currency,
                'aptos_address'=> $walletAddress,
                'counterparty' => $senderAddress,
                'tx_hash'      => $txHash,
                'explorer_url' => $explorerUrl,
            ]);
            error_log("Deposit recorded in transaction_history for $phoneNumber: $txHash");
        } catch (PDOException $e) {
            error_log("Failed to record deposit in history: " . $e->getMessage());
        }
    }

    sendJsonResponse($result, $result['success'] ? 200 : 400);
}