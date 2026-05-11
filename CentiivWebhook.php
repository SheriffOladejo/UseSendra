<?php

/**
 * Centiiv → single HTTPS endpoint (Svix / HMAC).
 *
 * 1) **Logistics** — Direct Pay `payment.successful` (driver acceptance fee).
 * 2) **Sendra Aptos** — liquidity API (`api.centiiv.io`) sends **`event_type`** payloads such as
 *    `request.completed` and `settlement.completed` (often **without** `metadata` in the POST body).
 *    We load full order details via `GET /requests/{requestId}` and notify when it is a Sendra order
 *    (`metadata.source === sendra_whatsapp`). Merchant/Svix **`disbursement.status`** is also supported
 *    when metadata is present ([catalog](https://www.svix.com/event-types/eu/org_2yieL70Sd2Iom1IBeqNlEWvzRoX/)).
 *
 * One URL in Centiiv (e.g. https://coinectra.stipendly.app/CentiivWebhook.php) can serve both.
 * Svix signing: Centiiv **Protocol** (aggregate / `api.centiiv.io` events) and **Payment** (Direct Pay, etc.)
 * are separate portal tabs — each endpoint has its **own** `whsec_`. We try every configured `whsec_` until one
 * verifies ({@see centiiv_webhook_collect_svix_secrets()}).
 *
 * @see https://docs.svix.com/receiving/introduction
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/html/php-debug.log');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';
require __DIR__ . '/config/Database.php';

$bots = require __DIR__ . '/config/Bots.php';

use App\Models\DeliveryRequest;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Services\CentiivDirectPayService;
use App\Services\LogisticsAfterDriverFeePaidService;
use App\Services\SendraCentiivRampService;
use App\Services\SendraCentiivPayoutReceiptFactory;
use App\Services\SendraPayoutReceiptImageService;
use App\Services\SvixWebhookVerifier;
use App\Services\WhatsAppService;

$logisticsPhoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834';

$db = Database::getInstance($bots[$logisticsPhoneId]['db_config'], false)->getConnection();

$accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$deliveryRequestModel = new DeliveryRequest($db);
$driverModel = new LogisticsDriver($db);
$userModel = new LogisticsUser($db);
$whatsappService = new WhatsAppService($accessToken, $logisticsPhoneId);

$payload = (string) @file_get_contents('php://input');
$event = json_decode($payload, true);

error_log('CENTIIV WEBHOOK: ' . $payload);

$svixSecrets = centiiv_webhook_collect_svix_secrets();
$legacyWebhookSecret = centiiv_webhook_first_legacy_webhook_secret();
$webhookSigningConfigured = $svixSecrets !== [] || $legacyWebhookSecret !== '';

$allowUnsigned = strtolower(trim((string) ($_ENV['CENTIIV_WEBHOOK_ALLOW_UNSIGNED'] ?? getenv('CENTIIV_WEBHOOK_ALLOW_UNSIGNED') ?: '')));
$allowUnsigned = in_array($allowUnsigned, ['1', 'true', 'yes'], true);
if ($webhookSigningConfigured && $allowUnsigned) {
    error_log('CENTIIV WEBHOOK: CENTIIV_WEBHOOK_ALLOW_UNSIGNED is on — signature check skipped (use only for mock/curl tests, never in production)');
}
if ($webhookSigningConfigured && !$allowUnsigned) {
    if ($svixSecrets !== []) {
        $svixFail = SvixWebhookVerifier::verifyFailureMessageWithAnySecret($payload, $svixSecrets);
        if ($svixFail !== null) {
            error_log('CENTIIV WEBHOOK: Svix verification failed — ' . $svixFail);
            http_response_code(401);
            exit('Invalid signature');
        }
    } else {
        $sig = (string) (
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
            ?? $_SERVER['HTTP_X_CENTIIV_SIGNATURE']
            ?? $_SERVER['HTTP_X_SIGNATURE']
            ?? ''
        );
        $expected = hash_hmac('sha256', $payload, $legacyWebhookSecret);
        if ($sig === '' || !hash_equals($expected, $sig)) {
            error_log('CENTIIV WEBHOOK: legacy HMAC verification failed');
            http_response_code(401);
            exit('Invalid signature');
        }
    }
}

if (!is_array($event)) {
    http_response_code(200);
    exit('OK');
}

// Sendra Aptos — Centiiv conversion orders (same URL as logistics; distinct metadata).
if (centiiv_payload_is_sendra_ramp($event)) {
    centiiv_handle_sendra_ramp_webhook($event, $accessToken, $aptosPhoneId);
    http_response_code(200);
    echo 'OK';
    exit;
}

// Sendra — api.centiiv.io aggregator webhooks (`event_type`, no metadata in body).
if (centiiv_payload_is_sendra_aggregator_completion($event)) {
    centiiv_handle_sendra_aggregator_completion_webhook($event, $accessToken, $aptosPhoneId);
    http_response_code(200);
    echo 'OK';
    exit;
}

$eventType = (string) ($event['event'] ?? '');
if ($eventType !== 'payment.successful') {
    http_response_code(200);
    exit('OK');
}

$data = $event['data'] ?? [];
if (!is_array($data)) {
    http_response_code(200);
    exit('OK');
}

$paymentStatus = strtolower((string) ($data['status'] ?? ''));
if ($paymentStatus === 'failed' || str_contains($paymentStatus, 'fail') || str_contains($paymentStatus, 'cancel')) {
    $parsed = centiiv_resolve_logistics_payment($data, $deliveryRequestModel);
    if ($parsed !== null) {
        centiiv_release_reservation_if_pending(
            $deliveryRequestModel,
            (int) $parsed['request_id'],
            (int) $parsed['driver_id'],
            (string) $parsed['reference']
        );
    }
    http_response_code(200);
    exit('OK');
}

if ($paymentStatus !== 'successful') {
    http_response_code(200);
    exit('OK');
}

$parsed = centiiv_resolve_logistics_payment($data, $deliveryRequestModel);
if ($parsed === null || ($parsed['type'] ?? '') !== 'driver_acceptance_fee') {
    $rid = (string) (($data['metadata'] ?? [])['resourceId'] ?? '');
    error_log('CENTIIV_WEBHOOK_SKIP could not map payment to job (check centiiv_dpl_id column + migration). resourceId=' . $rid);
    http_response_code(200);
    exit('OK');
}

$requestId = (int) $parsed['request_id'];
$driverId = (int) $parsed['driver_id'];
$reference = (string) $parsed['reference'];

$amountMinor = centiiv_amount_to_kobo_from_payment_data($data);
$pm = $data['paymentMethod'] ?? null;
$channel = 'centiiv';
if (is_array($pm)) {
    $channel = (string) ($pm['method'] ?? $pm['network'] ?? 'centiiv');
} elseif (is_string($pm) && $pm !== '') {
    $channel = $pm;
}

$ledgerData = [
    'reference' => $reference !== '' ? $reference : (string) ($data['transactionId'] ?? ''),
    'amount' => $amountMinor,
    'currency' => strtoupper((string) ($data['currency'] ?? 'NGN')),
    'channel' => $channel,
    'id' => (string) ($data['transactionId'] ?? ''),
    'paid_at' => $event['timestamp'] ?? date('c'),
    'customer' => $data['customer'] ?? null,
];

$centiivResourceId = (string) (($data['metadata'] ?? [])['resourceId'] ?? '');
$metadata = [
    'type' => 'driver_acceptance_fee',
    'request_id' => $requestId,
    'driver_id' => $driverId,
    'centiiv_resource_id' => $centiivResourceId,
];

$finalize = new LogisticsAfterDriverFeePaidService($whatsappService, __DIR__);
$finalize->run(
    $db,
    $requestId,
    $driverId,
    $reference,
    $ledgerData,
    $metadata,
    $deliveryRequestModel,
    $driverModel,
    $userModel
);

http_response_code(200);
echo 'OK';

/**
 * Unique `whsec_` secrets in env order (Payment vs Protocol vs Sendra may each use a different Svix key).
 *
 * @return list<string>
 */
function centiiv_webhook_collect_svix_secrets(): array
{
    $keys = [
        $_ENV['CENTIIV_WEBHOOK_SECRET'] ?? getenv('CENTIIV_WEBHOOK_SECRET'),
        $_ENV['CENTIIV_PROTOCOL_WEBHOOK_SECRET'] ?? getenv('CENTIIV_PROTOCOL_WEBHOOK_SECRET'),
        $_ENV['SENDRA_CENTIIV_PROTOCOL_WEBHOOK_SECRET'] ?? getenv('SENDRA_CENTIIV_PROTOCOL_WEBHOOK_SECRET'),
        $_ENV['SENDRA_CENTIIV_OFFRAMP_WEBHOOK_SECRET'] ?? getenv('SENDRA_CENTIIV_OFFRAMP_WEBHOOK_SECRET'),
        $_ENV['SENDRA_CENTIIV_WEBHOOK_SECRET'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_SECRET'),
    ];
    $out = [];
    foreach ($keys as $raw) {
        $s = trim((string) (($raw !== false && $raw !== null) ? $raw : ''));
        if ($s !== '' && str_starts_with($s, 'whsec_') && !in_array($s, $out, true)) {
            $out[] = $s;
        }
    }

    return $out;
}

/** First non-whsec secret in the same env order (legacy HMAC). */
function centiiv_webhook_first_legacy_webhook_secret(): string
{
    $keys = [
        $_ENV['CENTIIV_WEBHOOK_SECRET'] ?? getenv('CENTIIV_WEBHOOK_SECRET'),
        $_ENV['CENTIIV_PROTOCOL_WEBHOOK_SECRET'] ?? getenv('CENTIIV_PROTOCOL_WEBHOOK_SECRET'),
        $_ENV['SENDRA_CENTIIV_PROTOCOL_WEBHOOK_SECRET'] ?? getenv('SENDRA_CENTIIV_PROTOCOL_WEBHOOK_SECRET'),
        $_ENV['SENDRA_CENTIIV_OFFRAMP_WEBHOOK_SECRET'] ?? getenv('SENDRA_CENTIIV_OFFRAMP_WEBHOOK_SECRET'),
        $_ENV['SENDRA_CENTIIV_WEBHOOK_SECRET'] ?? getenv('SENDRA_CENTIIV_WEBHOOK_SECRET'),
    ];
    foreach ($keys as $raw) {
        $s = trim((string) (($raw !== false && $raw !== null) ? $raw : ''));
        if ($s !== '' && !str_starts_with($s, 'whsec_')) {
            return $s;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $data
 * @return array{type:string,request_id:int,driver_id:int,reference:string}|null
 */
function centiiv_resolve_logistics_payment(array $data, DeliveryRequest $deliveryRequestModel): ?array
{
    $meta = $data['metadata'] ?? [];
    if (is_object($meta)) {
        $meta = (array) $meta;
    }
    if (!is_array($meta)) {
        $meta = [];
    }

    $resourceId = (string) ($meta['resourceId'] ?? $meta['trackingId'] ?? '');

    // Prefer DB row keyed by DPL-* (set when rider opens payment link flow — see migration 20260413)
    if ($resourceId !== '' && str_starts_with($resourceId, 'DPL-')) {
        $row = $deliveryRequestModel->findByCentiivDplId($resourceId);
        if (is_array($row)) {
            $ref = (string) ($row['acceptance_payment_reference'] ?? '');
            $did = (int) ($row['reserved_driver_id'] ?? 0);
            if ($ref !== '' && $did > 0) {
                return [
                    'type' => 'driver_acceptance_fee',
                    'request_id' => (int) $row['id'],
                    'driver_id' => $did,
                    'reference' => $ref,
                ];
            }
        }
    }

    if ($resourceId !== '' && str_starts_with($resourceId, 'DRV_ACCEPT_')) {
        $req = $deliveryRequestModel->findByAcceptanceReference($resourceId);
        if (is_array($req)) {
            return [
                'type' => 'driver_acceptance_fee',
                'request_id' => (int) $req['id'],
                'driver_id' => (int) ($req['reserved_driver_id'] ?? 0),
                'reference' => $resourceId,
            ];
        }
        $rid = (int) ($meta['requestId'] ?? 0);
        $did = (int) ($meta['driverId'] ?? 0);
        if ($rid > 0 && $did > 0) {
            return [
                'type' => 'driver_acceptance_fee',
                'request_id' => $rid,
                'driver_id' => $did,
                'reference' => $resourceId,
            ];
        }
    }

    $note = $data['note'] ?? null;
    if (is_string($note)) {
        $parsed = CentiivDirectPayService::parseLogisticsNote($note);
        if (is_array($parsed) && ($parsed['type'] ?? '') === 'driver_acceptance_fee') {
            return $parsed;
        }
    }

    // Centiiv webhooks use payment-link id (e.g. DPL-6fda07) in metadata.resourceId
    if ($resourceId !== '') {
        $svc = new CentiivDirectPayService();
        if ($svc->isConfigured()) {
            $fromApi = $svc->resolveSendraFromPaymentLinkId($resourceId);
            if (is_array($fromApi)) {
                return $fromApi;
            }
        }
    }

    return null;
}

/**
 * Centiiv payment.successful example uses amount in smallest unit (e.g. kobo for NGN).
 *
 * @param array<string, mixed> $data
 */
function centiiv_amount_to_kobo_from_payment_data(array $data): int
{
    $raw = $data['amount'] ?? 0;
    if (is_string($raw)) {
        $raw = (float) $raw;
    }
    $n = (float) $raw;
    if ($n <= 0) {
        return 0;
    }
    // payment.successful sends NGN in smallest unit (kobo); do not multiply again (e.g. 8000 = ₦80).
    return (int) round($n);
}

function centiiv_release_reservation_if_pending(
    DeliveryRequest $deliveryRequestModel,
    int $requestId,
    int $driverId,
    string $reference
): void {
    if ($requestId < 1) {
        return;
    }
    $req = $deliveryRequestModel->findById($requestId);
    if (!$req || ($req['status'] ?? '') !== 'driver_payment_pending') {
        return;
    }
    if ((int) ($req['reserved_driver_id'] ?? 0) !== $driverId) {
        return;
    }
    $expectedRef = (string) ($req['acceptance_payment_reference'] ?? '');
    if ($expectedRef !== '' && $reference !== '' && $expectedRef !== $reference) {
        return;
    }
    $deliveryRequestModel->update($requestId, [
        'status' => 'searching_driver',
        'reserved_driver_id' => null,
        'reservation_expires_at' => null,
        'acceptance_payment_reference' => null,
    ]);
    error_log("Centiiv webhook: released reservation for req {$requestId} after non-success payment");
}

/**
 * Centiiv GET /requests/{id} nests custom fields under metadata.metadata (user_phone, source, …).
 *
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function centiiv_sendra_flatten_order_metadata(array $meta): array
{
    $inner = $meta['metadata'] ?? null;
    $base = $meta;
    if (is_array($inner)) {
        unset($base['metadata']);

        return array_merge($base, $inner);
    }

    return $base;
}

/**
 * Atomic "claim" so duplicate webhooks do not double-notify; file left in place after successful send.
 */
function centiiv_sendra_try_claim_terminal_notify(string $orderKey): bool
{
    $orderKey = trim($orderKey);
    if ($orderKey === '' || strtolower($orderKey) === 'order') {
        return true;
    }
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $orderKey);
    $path = sys_get_temp_dir() . '/sendra_centiiv_terminal_' . $safe;
    $h = @fopen($path, 'xb');
    if ($h === false) {
        return false;
    }
    fwrite($h, (string) time());
    fclose($h);

    return true;
}

function centiiv_sendra_release_terminal_notify_lock(string $orderKey): void
{
    $orderKey = trim($orderKey);
    if ($orderKey === '' || strtolower($orderKey) === 'order') {
        return;
    }
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $orderKey);
    @unlink(sys_get_temp_dir() . '/sendra_centiiv_terminal_' . $safe);
}

/**
 * @param array<string, mixed> $decoded GET /requests/{id} JSON body
 * @return array<string, mixed>
 */
function centiiv_unwrap_get_request_data(array $decoded): array
{
    $inner = $decoded['data'] ?? null;
    if (is_array($inner)) {
        return $inner;
    }

    return $decoded;
}

/**
 * Liquidity aggregator completion: `event_type` + `data.requestId` (webhook body has no metadata).
 *
 * @param array<string, mixed> $event
 */
function centiiv_payload_is_sendra_aggregator_completion(array $event): bool
{
    $type = (string) ($event['event_type'] ?? '');
    if (!in_array($type, ['request.completed', 'settlement.completed'], true)) {
        return false;
    }
    $data = $event['data'] ?? null;
    if (!is_array($data)) {
        return false;
    }
    $rid = $data['requestId'] ?? $data['request_id'] ?? null;
    if (!is_string($rid) || trim($rid) === '') {
        return false;
    }
    $st = strtolower((string) ($data['status'] ?? ''));
    if ($st === '') {
        return false;
    }

    return str_contains($st, 'fulfill') || str_contains($st, 'complete');
}

/**
 * Enrich aggregator webhook with GET /requests/{id} so metadata.user_phone is available.
 *
 * @param array<string, mixed> $event
 */
function centiiv_handle_sendra_aggregator_completion_webhook(array $event, string $accessToken, string $aptosPhoneId): void
{
    $data = $event['data'] ?? [];
    if (!is_array($data)) {
        return;
    }
    $rid = isset($data['requestId']) && is_string($data['requestId']) ? trim($data['requestId']) : '';
    if ($rid === '') {
        return;
    }
    $agg = new SendraCentiivRampService();
    if (!$agg->isConfigured()) {
        error_log('CENTIIV WEBHOOK aggregator: SENDRA_CENTIIV_JWT not set; cannot enrich ' . $rid);

        return;
    }
    $res = $agg->getRequest($rid);
    if (!$res['success']) {
        error_log('CENTIIV WEBHOOK aggregator: getRequest failed ' . $rid . ' http=' . (string) ($res['http_code'] ?? 0));

        return;
    }
    $order = centiiv_unwrap_get_request_data($res['data']);
    $rawMeta = $order['metadata'] ?? [];
    if (is_object($rawMeta)) {
        $rawMeta = (array) $rawMeta;
    }
    if (!is_array($rawMeta)) {
        $rawMeta = [];
    }
    $flatMeta = centiiv_sendra_flatten_order_metadata($rawMeta);
    $src = (string) ($flatMeta['source'] ?? '');
    $phone = trim((string) ($flatMeta['user_phone'] ?? $flatMeta['userPhone'] ?? ''));
    if ($src !== 'sendra_whatsapp' && $phone === '') {
        error_log('CENTIIV WEBHOOK aggregator: skip non-Sendra order ' . $rid);

        return;
    }
    error_log('CENTIIV WEBHOOK aggregator: Sendra completion for ' . $rid . ' wa=' . $phone);
    $syntheticData = array_merge($order, $data);
    $syntheticData['metadata'] = $flatMeta;
    $synthetic = [
        'data' => $syntheticData,
        'timestamp' => $event['timestamp'] ?? null,
    ];
    centiiv_handle_sendra_ramp_webhook($synthetic, $accessToken, $aptosPhoneId);
}

/**
 * Sendra Aptos sell/buy — order status webhooks from api.centiiv.io (metadata.source = sendra_whatsapp).
 *
 * @param array<string, mixed> $event
 */
function centiiv_handle_sendra_ramp_webhook(array $event, string $accessToken, string $aptosPhoneId): void
{
    $wa = new WhatsAppService($accessToken, $aptosPhoneId);
    $norm = centiiv_sendra_normalize_conversion_event($event);
    $phone = $norm['user_phone'];
    $status = $norm['status'];
    $orderLabel = $norm['order_label'];
    $oneclickRef = isset($norm['oneclick_correlation_id']) ? trim((string) $norm['oneclick_correlation_id']) : '';
    $refLine = $oneclickRef !== '' ? ("\n1Click ref: `{$oneclickRef}`") : '';

    if ($phone === null || $phone === '') {
        error_log('CENTIIV WEBHOOK sendra: no user_phone in metadata; ack only');

        return;
    }

    $statusLc = strtolower($status);
    if ($statusLc === '') {
        return;
    }

    if (centiiv_sendra_is_terminal_success($statusLc)) {
        if (!centiiv_sendra_try_claim_terminal_notify($orderLabel)) {
            error_log('CENTIIV WEBHOOK sendra: duplicate terminal success skipped for ' . $orderLabel);

            return;
        }
        $notifyOk = false;
        $receiptPath = null;
        try {
            $receiptModel = SendraCentiivPayoutReceiptFactory::build($event, $norm);
            $receiptPath = SendraPayoutReceiptImageService::renderToTempFile($receiptModel);
        } catch (\Throwable $e) {
            error_log('CENTIIV WEBHOOK sendra receipt: ' . $e->getMessage());
        }
        try {
            if ($receiptPath !== null && is_string($receiptPath) && is_readable($receiptPath)) {
                $imgRes = $wa->sendImage($phone, $receiptPath, '✅ Sendra — bank payout receipt');
                if ($imgRes === false) {
                    error_log('CENTIIV WEBHOOK sendra: sendImage upload failed for ' . $phone);
                } elseif (is_array($imgRes) && empty($imgRes['success'])) {
                    error_log('CENTIIV WEBHOOK sendra: sendImage API failed for ' . $phone);
                }
                @unlink($receiptPath);
            }
            $msgRes = $wa->sendMessage(
                $phone,
                "✅ *Bank payout complete*\n\n" .
                "Order `{$orderLabel}` — NGN should credit your account shortly if it has not already.{$refLine}\n\n" .
                'Need help? support@usesendra.com'
            );
            $notifyOk = is_array($msgRes) && !empty($msgRes['success']);
            if (!$notifyOk) {
                error_log('CENTIIV WEBHOOK sendra: sendMessage failed for ' . $phone);
            }
        } finally {
            if (!$notifyOk) {
                centiiv_sendra_release_terminal_notify_lock($orderLabel);
            }
        }
    } elseif (centiiv_sendra_is_terminal_failure($statusLc)) {
        $wa->sendMessage(
            $phone,
            "⚠️ *Conversion update*\n\n" .
            "Your Centiiv order ({$orderLabel}) reported: *{$status}*.{$refLine}\n" .
            "If you already sent crypto, check your email or contact support@usesendra.com with this message."
        );
    }
}

/**
 * Merged metadata from Svix envelope (data.metadata + top-level metadata).
 *
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function centiiv_sendra_extract_metadata(array $event): array
{
    $data = $event['data'] ?? null;
    if (!is_array($data)) {
        $data = [];
    }
    $meta = $data['metadata'] ?? [];
    if (is_object($meta)) {
        $meta = (array) $meta;
    }
    if (!is_array($meta)) {
        $meta = [];
    }
    $meta = centiiv_sendra_flatten_order_metadata($meta);
    $top = $event['metadata'] ?? [];
    if (is_object($top)) {
        $top = (array) $top;
    }
    if (!is_array($top)) {
        $top = [];
    }
    $top = centiiv_sendra_flatten_order_metadata($top);

    return array_merge($top, $meta);
}

/**
 * @param array<string, mixed> $event
 */
function centiiv_payload_is_sendra_ramp(array $event): bool
{
    $meta = centiiv_sendra_extract_metadata($event);
    if (($meta['source'] ?? '') === 'sendra_whatsapp') {
        return true;
    }

    // Newer Centiiv order status webhooks may omit metadata.source/user_phone but still be Sendra:
    // - beneficiary.externalId like "sendra_wa_2348..."
    // - description like "Sendra WhatsApp sell 2347..."
    $data = $event['data'] ?? null;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        $data = [];
    }
    $beneficiary = $data['beneficiary'] ?? null;
    if (is_object($beneficiary)) {
        $beneficiary = (array) $beneficiary;
    }
    if (is_array($beneficiary)) {
        $externalId = $beneficiary['externalId'] ?? $beneficiary['external_id'] ?? null;
        if (is_string($externalId) && str_starts_with($externalId, 'sendra_wa_')) {
            return true;
        }
    }
    $desc = $data['description'] ?? $event['description'] ?? null;
    if (is_string($desc) && preg_match('/\bSendra\s+WhatsApp\s+sell\s+(\d{10,20})\b/i', $desc) === 1) {
        return true;
    }

    $eventType = (string) ($event['event'] ?? '');
    if ($eventType === 'disbursement.status') {
        $phone = trim((string) ($meta['user_phone'] ?? $meta['userPhone'] ?? ''));
        if ($phone === '') {
            return false;
        }
        if (!empty($meta['sendra_oneclick_correlation_id']) || !empty($meta['sendra_ledger_asset']) || !empty($meta['sendra_ledger_amount'])) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $event
 * @return array{user_phone: ?string, status: string, order_label: string, oneclick_correlation_id: string}
 */
function centiiv_sendra_normalize_conversion_event(array $event): array
{
    $data = $event['data'] ?? $event;

    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        $data = [];
    }

    $meta = centiiv_sendra_extract_metadata($event);

    $phone = $meta['user_phone'] ?? $meta['userPhone'] ?? null;
    if (is_string($phone)) {
        $phone = trim($phone);
    } else {
        $phone = null;
    }
    if ($phone === null || $phone === '') {
        $beneficiary = $data['beneficiary'] ?? null;
        if (is_object($beneficiary)) {
            $beneficiary = (array) $beneficiary;
        }
        if (is_array($beneficiary)) {
            $externalId = $beneficiary['externalId'] ?? $beneficiary['external_id'] ?? null;
            if (is_string($externalId) && preg_match('/^sendra_wa_(\d{10,20})$/', $externalId, $m) === 1) {
                $phone = $m[1];
            }
        }
    }
    if ($phone === null || $phone === '') {
        $desc = $data['description'] ?? $event['description'] ?? null;
        if (is_string($desc) && preg_match('/\bSendra\s+WhatsApp\s+sell\s+(\d{10,20})\b/i', $desc, $m) === 1) {
            $phone = $m[1];
        }
    }

    $status = (string) ($data['status'] ?? $data['orderStatus'] ?? $event['status'] ?? '');
    $id = $data['id'] ?? $data['orderId'] ?? $data['requestId'] ?? $data['entryId'] ?? $data['entry_id'] ?? null;
    if (($id === null || $id === '') && isset($meta['sendra_centiiv_order_id'])) {
        $id = $meta['sendra_centiiv_order_id'];
    }
    if (($id === null || $id === '') && isset($meta['trackingId'])) {
        $id = $meta['trackingId'];
    }
    if ($id === null || $id === '') {
        $id = $event['id'] ?? '';
    }
    $orderLabel = $id !== '' ? (string) $id : 'order';

    $oc = isset($meta['sendra_oneclick_correlation_id']) ? trim((string) $meta['sendra_oneclick_correlation_id']) : '';
    if ($oc === '' && isset($meta['oneclick_correlation_id'])) {
        $oc = trim((string) $meta['oneclick_correlation_id']);
    }

    return [
        'user_phone' => $phone,
        'status' => $status,
        'order_label' => $orderLabel,
        'oneclick_correlation_id' => $oc,
    ];
}

function centiiv_sendra_is_terminal_success(string $statusLc): bool
{
    foreach (['fulfilled', 'complete', 'completed', 'success', 'paid', 'settled'] as $needle) {
        if (str_contains($statusLc, $needle)) {
            return true;
        }
    }

    return false;
}

function centiiv_sendra_is_terminal_failure(string $statusLc): bool
{
    foreach (['fail', 'cancel', 'expire', 'reject', 'error'] as $needle) {
        if (str_contains($statusLc, $needle)) {
            return true;
        }
    }

    return false;
}
