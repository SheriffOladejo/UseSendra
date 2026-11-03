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
 * One URL in Centiiv (e.g. https://usesendra.com/CentiivWebhook.php) can serve both.
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
use App\Models\RiderWalletTransaction;
use App\Models\MarketplaceOrder;
use App\Services\Marketplace\MarketplaceAfterPaymentService;
use App\Services\Marketplace\MarketplaceWhatsAppHandler;
use App\Services\CentiivDirectPayService;
use App\Services\LogisticsAfterDriverFeePaidService;
use App\Services\RiderWalletService;
use App\Services\SendraCentiivRampService;
use App\Services\SendraCentiivPayoutReceiptFactory;
use App\Services\SendraOfframpSlippageService;
use App\Services\SendraOneClickService;
use App\Services\SendraPayoutReceiptImageService;
use App\Services\SvixWebhookVerifier;
use App\Services\WhatsAppService;
use AptosV2\Support\WhatsAppBotResolver;

$logisticsPhoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834';

$db = Database::getInstance($bots[$logisticsPhoneId]['db_config'], false)->getConnection();
$aptosDb = Database::getInstance($bots[$aptosPhoneId]['db_config'], false)->getConnection();

$accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$deliveryRequestModel = new DeliveryRequest($db);
$driverModel = new LogisticsDriver($db);
$userModel = new LogisticsUser($db);
$whatsappService = new WhatsAppService($accessToken, $logisticsPhoneId);

if (php_sapi_name() !== 'cli') {
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

// Banking disburse webhooks use `event` (not `event_type`) and often omit Sendra order metadata.
$webhookEventType = centiiv_webhook_event_type($event);
if ($webhookEventType === 'disbursement.status') {
    if (centiiv_sendra_handle_intermediary_disbursement_webhook($event, $accessToken, $aptosPhoneId, $aptosDb)) {
        http_response_code(200);
        echo 'OK';
        exit;
    }
}

// Centiiv sometimes sends order.* webhooks with data = order UUID string only (no beneficiary/description).
if (centiiv_try_handle_sendra_order_id_status_webhook($event, $accessToken, $aptosPhoneId, $aptosDb)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// Sendra Aptos — Centiiv conversion orders (same URL as logistics; distinct metadata).
if (centiiv_payload_is_sendra_ramp($event)) {
    $rampMeta = centiiv_sendra_extract_metadata($event);
    if (($rampMeta['source'] ?? '') === 'sendra_logistics_rider') {
        centiiv_handle_logistics_rider_withdraw_webhook(
            $event,
            $rampMeta,
            trim((string) ($rampMeta['driver_phone'] ?? '')),
            (string) ($rampMeta['reference'] ?? ($event['data']['id'] ?? 'order')),
            $whatsappService,
            $db,
            $driverModel
        );
    } else {
        centiiv_handle_sendra_ramp_webhook($event, $accessToken, $aptosPhoneId, $aptosDb);
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

// Sendra — api.centiiv.io aggregator webhooks (`event_type`, no metadata in body).
if (centiiv_payload_is_sendra_aggregator_completion($event)) {
    centiiv_handle_sendra_aggregator_completion_webhook(
        $event,
        $accessToken,
        $aptosPhoneId,
        $whatsappService,
        $db,
        $driverModel,
        $aptosDb
    );
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

$marketplacePaid = centiiv_resolve_marketplace_payment($data, $db, $whatsappService);
if ($marketplacePaid) {
    http_response_code(200);
    exit('OK');
}

$parsed = centiiv_resolve_logistics_payment($data, $deliveryRequestModel);
if ($parsed === null) {
    $rid = (string) (($data['metadata'] ?? [])['resourceId'] ?? '');
    error_log('CENTIIV_WEBHOOK_SKIP could not map payment (check centiiv_dpl_id column + migration). resourceId=' . $rid);
    http_response_code(200);
    exit('OK');
}

if (($parsed['type'] ?? '') === 'rider_wallet_fund') {
    $driverId = (int) ($parsed['driver_id'] ?? 0);
    $reference = (string) ($parsed['reference'] ?? '');
    $amountMinor = centiiv_amount_to_kobo_from_payment_data($data);
    $amountNgn = $amountMinor / 100;
    if ($driverId > 0 && $reference !== '' && $amountNgn > 0) {
        $wallet = new RiderWalletService(
            $db,
            $driverModel,
            new RiderWalletTransaction($db)
        );
        $centiivResourceId = (string) (($data['metadata'] ?? [])['resourceId'] ?? '');
        $credited = $wallet->creditFund($driverId, $amountNgn, $reference, [
            'centiiv_resource_id' => $centiivResourceId,
        ]);
        if ($credited) {
            $driver = $driverModel->findById($driverId);
            if ($driver && !empty($driver['phone'])) {
                $whatsappService->sendMessage(
                    (string) $driver['phone'],
                    '✅ ₦' . number_format($amountNgn, 0) . " added to your wallet.\nType *balance* or *history* to see details."
                );
            }
        }
    }
    http_response_code(200);
    exit('OK');
}

if (($parsed['type'] ?? '') !== 'driver_acceptance_fee') {
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
}

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

    if ($resourceId !== '' && str_starts_with($resourceId, 'RIDER_FUND_')) {
        $parsed = CentiivDirectPayService::parseLogisticsNote(is_string($data['note'] ?? null) ? $data['note'] : null);
        if (is_array($parsed) && ($parsed['type'] ?? '') === 'rider_wallet_fund') {
            return $parsed;
        }
        $did = (int) ($meta['driverId'] ?? 0);
        if ($did > 0) {
            return [
                'type' => 'rider_wallet_fund',
                'request_id' => 0,
                'driver_id' => $did,
                'reference' => $resourceId,
            ];
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
 * Marketplace multi-store checkout payment.
 *
 * @param array<string, mixed> $data
 */
function centiiv_resolve_marketplace_payment(array $data, PDO $db, WhatsAppService $whatsappService): bool
{
    $meta = $data['metadata'] ?? [];
    if (is_object($meta)) {
        $meta = (array) $meta;
    }
    if (!is_array($meta)) {
        $meta = [];
    }
    $resourceId = (string) ($meta['resourceId'] ?? $meta['trackingId'] ?? '');
    $orderModel = new MarketplaceOrder($db);
    $order = null;
    if ($resourceId !== '' && str_starts_with($resourceId, 'MKT_PAY_')) {
        $order = $orderModel->findByPaymentReference($resourceId);
    }
    if ($order === null && $resourceId !== '' && str_starts_with($resourceId, 'DPL-')) {
        $order = $orderModel->findByCentiivDplId($resourceId);
    }
    $note = is_string($data['note'] ?? null) ? $data['note'] : null;
    $parsedNote = CentiivDirectPayService::parseMarketplaceNote($note);
    if ($order === null && $parsedNote !== null) {
        $order = $orderModel->findById((int) $parsedNote['marketplace_order_id']);
    }
    if ($order === null) {
        return false;
    }
    if (($order['payment_status'] ?? '') === 'paid') {
        return true;
    }
    $amountMinor = centiiv_amount_to_kobo_from_payment_data($data);
    $amountNgn = $amountMinor / 100;
    $ref = (string) ($order['payment_reference'] ?? $resourceId);
    $handler = MarketplaceWhatsAppHandler::build($db, $whatsappService);

    return $handler->afterPayment()->handlePaid((int) $order['id'], $amountNgn > 0 ? $amountNgn : (float) $order['total_ngn'], $ref);
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
function centiiv_handle_sendra_aggregator_completion_webhook(
    array $event,
    string $accessToken,
    string $aptosPhoneId,
    WhatsAppService $logisticsWa,
    \PDO $db,
    LogisticsDriver $driverModel,
    \PDO $aptosDb
): void {
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
    $phone = trim((string) ($flatMeta['driver_phone'] ?? $flatMeta['user_phone'] ?? $flatMeta['userPhone'] ?? ''));
    if ($src === 'sendra_logistics_rider') {
        centiiv_handle_logistics_rider_withdraw_webhook($event, $flatMeta, $phone, $rid, $logisticsWa, $db, $driverModel);

        return;
    }
    if ($phone === '') {
        $descPhone = centiiv_sendra_phone_from_order_description(
            is_string($order['description'] ?? null) ? (string) $order['description'] : null
        );
        if ($descPhone !== null) {
            $phone = $descPhone;
        }
    }
    $benExt = '';
    $ben = $order['beneficiary'] ?? null;
    if (is_object($ben)) {
        $ben = (array) $ben;
    }
    if (is_array($ben)) {
        $benExt = (string) ($ben['externalId'] ?? $ben['external_id'] ?? '');
    }
    $isSendraBeneficiary = $benExt !== '' && (
        str_starts_with($benExt, 'sendra_wa_')
        || $benExt === 'sendra_intermediary_ngn'
        || str_starts_with($benExt, 'sendra_')
    );
    if ($src !== 'sendra_whatsapp' && $phone === '' && !$isSendraBeneficiary) {
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
    centiiv_handle_sendra_ramp_webhook($synthetic, $accessToken, $aptosPhoneId, $aptosDb);
}

/**
 * SR-… reference we attached at order create (Opay/Nomba narration); Centiiv GET often omits it.
 */
function centiiv_sendra_lookup_payout_reference(\PDO $db, string $centiivOrderId): ?string
{
    $centiivOrderId = trim($centiivOrderId);
    if ($centiivOrderId === '') {
        return null;
    }

    try {
        $stmt = $db->prepare(
            'SELECT human_description FROM pending_transactions
             WHERE human_description LIKE :needle
             ORDER BY id DESC
             LIMIT 8'
        );
        $stmt->execute(['needle' => '%' . $centiivOrderId . '%']);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $raw = $row['human_description'] ?? '';
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $oid = trim((string) ($decoded['centiiv_order_id'] ?? $decoded['order_id'] ?? ''));
            if ($oid !== '' && $oid !== $centiivOrderId) {
                continue;
            }
            $ref = trim((string) ($decoded['sendra_payout_reference'] ?? ''));
            if ($ref !== '') {
                return $ref;
            }
        }
    } catch (\Throwable $e) {
        error_log('CENTIIV WEBHOOK sendra: payout reference lookup failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * @return ?array<string, mixed> pending_transactions.human_description + row fields
 */
function centiiv_sendra_lookup_pending_offramp_meta(\PDO $db, string $centiivOrderId): ?array
{
    $centiivOrderId = trim($centiivOrderId);
    if ($centiivOrderId === '') {
        return null;
    }

    try {
        $stmt = $db->prepare(
            'SELECT tx_type, amount, currency, human_description
             FROM pending_transactions
             WHERE human_description LIKE :needle
             ORDER BY id DESC
             LIMIT 8'
        );
        $stmt->execute(['needle' => '%' . $centiivOrderId . '%']);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $raw = $row['human_description'] ?? '';
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $oid = trim((string) ($decoded['centiiv_order_id'] ?? $decoded['order_id'] ?? ''));
            if ($oid !== '' && $oid !== $centiivOrderId) {
                continue;
            }

            return [
                'tx_type' => (string) ($row['tx_type'] ?? ''),
                'amount' => $row['amount'] ?? null,
                'currency' => (string) ($row['currency'] ?? ''),
                'meta' => $decoded,
            ];
        }
    } catch (\Throwable $e) {
        error_log('CENTIIV WEBHOOK sendra: pending offramp lookup failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * @return array<string, mixed>|null pending_transactions.human_description JSON
 */
function centiiv_sendra_pending_meta_for_order(\PDO $db, string $orderLabel): ?array
{
    $pending = centiiv_sendra_lookup_pending_offramp_meta($db, $orderLabel);
    if ($pending === null) {
        return null;
    }
    $meta = $pending['meta'] ?? null;

    return is_array($meta) ? $meta : null;
}

/**
 * WhatsApp client for Sendra user notifications — route v2 flows to the originating bot.
 *
 * @param array<string, mixed>|null $pendingMeta
 */
function centiiv_sendra_whatsapp_for_user(
    string $accessToken,
    string $defaultPhoneId,
    \PDO $aptosDb,
    string $userPhone,
    ?array $pendingMeta = null
): WhatsAppService {
    $phoneId = WhatsAppBotResolver::resolvePhoneNumberIdForOutbound(
        $aptosDb,
        $userPhone,
        $pendingMeta,
        $defaultPhoneId
    );
    if ($phoneId === '') {
        $phoneId = $defaultPhoneId;
    }

    return new WhatsAppService($accessToken, $phoneId);
}

/**
 * @param array<string, mixed> $event
 */
function centiiv_sendra_record_offramp_outcome(\PDO $db, array $event, string $orderId, ?string $phone): void
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return;
    }

    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        return;
    }

    $fulfillmentNgn = centiiv_sendra_fulfillment_ngn_from_order($data);
    if ($fulfillmentNgn === null || $fulfillmentNgn <= 0) {
        return;
    }

    $pending = centiiv_sendra_lookup_pending_offramp_meta($db, $orderId);
    $meta = is_array($pending['meta'] ?? null) ? $pending['meta'] : [];

    $targetNgn = (float) ($meta['target_receive_ngn'] ?? 0);
    if ($targetNgn <= 0) {
        $sellCtx = is_array($meta['sell_ctx'] ?? null) ? $meta['sell_ctx'] : [];
        $targetNgn = SendraCentiivRampService::resolveIntermediaryRequestedNgnFromPendingMeta($meta, $sellCtx);
    }
    if ($targetNgn <= 0) {
        $desc = (string) ($data['description'] ?? '');
        if (centiiv_sendra_phone_from_order_description($desc) !== null) {
            $targetNgn = $fulfillmentNgn;
        }
    }

    $quotedRaw = $meta['receive_ngn_display'] ?? null;
    $quotedNgn = null;
    if (is_string($quotedRaw) || is_numeric($quotedRaw)) {
        $quotedNgn = (float) str_replace(',', '', (string) $quotedRaw);
        if ($quotedNgn <= 0) {
            $quotedNgn = null;
        }
    }

    $usdtDebit = null;
    if (isset($pending['amount']) && is_numeric($pending['amount'])) {
        $usdtDebit = (float) $pending['amount'];
    } elseif (isset($meta['sendra_ledger_amount']) && is_numeric($meta['sendra_ledger_amount'])) {
        $usdtDebit = (float) $meta['sendra_ledger_amount'];
    }

    $panoraWallet = isset($meta['panora_to_token_amount']) ? (float) $meta['panora_to_token_amount'] : null;
    if ($panoraWallet !== null && $panoraWallet <= 0) {
        $panoraWallet = null;
    }

    $orderUsdc = isset($meta['centiiv_usdc_amount']) ? (float) $meta['centiiv_usdc_amount'] : null;
    if ($orderUsdc !== null && $orderUsdc <= 0) {
        $orderUsdc = null;
    }

    $stellarDeposit = centiiv_sendra_stellar_deposit_usdc_from_order($data);
    if ($stellarDeposit === null && $orderUsdc !== null) {
        $stellarDeposit = $orderUsdc;
    }

    $path = 'unknown';
    $txType = (string) ($pending['tx_type'] ?? '');
    if ($txType === 'offramp_panora_oneclick_centiiv') {
        $path = 'panora_oneclick';
    } elseif ($txType === 'offramp_oneclick_centiiv') {
        $path = 'oneclick';
    } elseif ($txType === 'offramp_stellar_centiiv') {
        $path = 'stellar_native';
    } elseif ($txType === 'offramp_arc_centiiv') {
        $path = 'arc_native';
    } elseif ($txType === 'offramp_centiiv') {
        $path = 'centiiv_direct';
    }

    $oneClickDeposit = trim((string) ($pending['recipient_address'] ?? ''));
    if ($oneClickDeposit === '' || !str_starts_with(strtolower($oneClickDeposit), '0x')) {
        $oneClickDeposit = trim((string) ($meta['oneclick_deposit_address'] ?? $meta['deposit_address'] ?? ''));
    }
    if ($oneClickDeposit !== '' && !str_starts_with(strtolower($oneClickDeposit), '0x')) {
        $oneClickDeposit = '';
    }
    $oneClickSwapId = trim((string) (
        $meta['oneclick_correlation_id']
        ?? $meta['oneclick_correlation']
        ?? $meta['sendra_oneclick_correlation_id']
        ?? ''
    ));
    $aptosTxHash = trim((string) ($pending['tx_hash'] ?? $meta['aptos_funding_tx_hash'] ?? $meta['aptos_tx_hash'] ?? ''));
    if ($aptosTxHash !== '' && !str_starts_with(strtolower($aptosTxHash), '0x')) {
        $aptosTxHash = '';
    }

    $ok = SendraOfframpSlippageService::recordOutcome([
        'centiiv_order_id' => $orderId,
        'user_phone' => $phone,
        'offramp_path' => $path,
        'target_ngn' => $targetNgn,
        'quoted_ngn_at_pin' => $quotedNgn,
        'fulfillment_ngn' => $fulfillmentNgn,
        'usdt_debit' => $usdtDebit,
        'panora_wallet_usdc' => $panoraWallet,
        'stellar_deposit_usdc' => $stellarDeposit,
        'centiiv_order_usdc' => $orderUsdc,
        'oneclick_deposit_address' => $oneClickDeposit !== '' ? $oneClickDeposit : null,
        'oneclick_swap_id' => $oneClickSwapId !== '' ? $oneClickSwapId : null,
        'aptos_tx_hash' => $aptosTxHash !== '' ? $aptosTxHash : null,
    ]);
    if ($ok) {
        $learned = SendraOfframpSlippageService::getLearnedBuffers();
        error_log(
            'Sendra offramp outcome recorded order=' . $orderId
            . ' target=' . $targetNgn . ' fulfillment=' . $fulfillmentNgn
            . ' learn_extra_ngn=' . ($learned['extra_ngn_p90'] ?? 0)
            . ' learn_haircut_bps=' . ($learned['haircut_bps_p90'] ?? 0)
            . ' n=' . ($learned['sample_count'] ?? 0)
        );
    }
}

/**
 * @param array<string, mixed> $data
 */
function centiiv_sendra_fulfillment_ngn_from_order(array $data): ?float
{
    if (isset($data['fulfillments'][0]) && is_array($data['fulfillments'][0])) {
        $f0 = $data['fulfillments'][0];
        $fa = $f0['amount'] ?? $f0['payoutAmount'] ?? $f0['payout_amount'] ?? null;
        if ($fa !== null && is_numeric($fa)) {
            return (float) $fa;
        }
    }

    $summary = SendraCentiivRampService::summarizeQuoteNgn($data, null);

    return $summary['receive_ngn'] ?? null;
}

/**
 * @param array<string, mixed> $data
 */
function centiiv_sendra_stellar_deposit_usdc_from_order(array $data): ?float
{
    if (strtoupper((string) ($data['fromAsset'] ?? '')) !== 'USDC') {
        return null;
    }
    $amt = $data['amount'] ?? null;
    if ($amt !== null && is_numeric($amt) && (float) $amt > 0) {
        return (float) $amt;
    }

    return null;
}

/**
 * Centiiv uses `event_type` on conversion orders and `event` on Direct Pay / banking webhooks.
 *
 * @param array<string, mixed> $event
 */
function centiiv_webhook_event_type(array $event): string
{
    return strtolower(trim((string) ($event['event_type'] ?? $event['event'] ?? '')));
}

/**
 * @param array<string, mixed> $data Centiiv order payload (event data)
 */
function centiiv_sendra_beneficiary_external_id(array $data): ?string
{
    $beneficiary = $data['beneficiary'] ?? null;
    if (is_object($beneficiary)) {
        $beneficiary = (array) $beneficiary;
    }
    if (!is_array($beneficiary)) {
        return null;
    }
    $externalId = $beneficiary['externalId'] ?? $beneficiary['external_id'] ?? null;

    return is_string($externalId) && $externalId !== '' ? trim($externalId) : null;
}

/**
 * Centiiv webhooks often omit custom metadata; detect intermediary off-ramps by beneficiary id.
 *
 * @param array<string, mixed> $event
 */
function centiiv_sendra_is_intermediary_offramp_event(array $event): bool
{
    $meta = centiiv_sendra_extract_metadata($event);
    if (($meta['sendra_intermediary_payout'] ?? '') === '1') {
        return true;
    }

    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        $data = [];
    }

    return centiiv_sendra_beneficiary_external_id($data) === 'sendra_intermediary_ngn';
}

/**
 * Fill sendra_intermediary_* fields from pending_transactions when Centiiv strips them from webhooks.
 *
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function centiiv_sendra_merge_intermediary_meta_from_pending(array $event, \PDO $aptosDb, string $orderLabel): array
{
    $meta = centiiv_sendra_extract_metadata($event);
    if (($meta['sendra_intermediary_payout'] ?? '') === '1'
        && !empty($meta['sendra_user_account_number'])
        && isset($meta['sendra_requested_ngn']) && is_numeric($meta['sendra_requested_ngn'])) {
        return $meta;
    }

    $pending = centiiv_sendra_lookup_pending_offramp_meta($aptosDb, $orderLabel);
    if ($pending === null) {
        return $meta;
    }

    $pm = $pending['meta'];
    $sellCtx = isset($pm['sell_ctx']) && is_array($pm['sell_ctx']) ? $pm['sell_ctx'] : [];

    if (($meta['sendra_intermediary_payout'] ?? '') !== '1') {
        $meta['sendra_intermediary_payout'] = '1';
    }
    if (empty($meta['sendra_requested_ngn'])) {
        $resolved = SendraCentiivRampService::resolveIntermediaryRequestedNgnFromPendingMeta($pm, $sellCtx);
        if ($resolved > 0) {
            $meta['sendra_requested_ngn'] = (string) $resolved;
        }
    }
    $userBank = SendraCentiivRampService::resolveIntermediaryUserBankFromPendingMeta($pm, $sellCtx);
    if (empty($meta['sendra_user_account_number'])) {
        $meta['sendra_user_account_number'] = $userBank['account_number'];
    }
    if (empty($meta['sendra_user_bank_code'])) {
        $meta['sendra_user_bank_code'] = $userBank['bank_code'];
    }
    if (empty($meta['sendra_user_account_name'])) {
        $meta['sendra_user_account_name'] = $userBank['account_name'];
    }
    if (empty($meta['sendra_user_bank_name'])) {
        $meta['sendra_user_bank_name'] = $userBank['bank_name'];
    }
    if (empty($meta['sendra_ledger_asset'])) {
        $meta['sendra_ledger_asset'] = strtoupper((string) ($pending['currency'] ?? 'USDT'));
    }
    if (empty($meta['sendra_ledger_amount']) && isset($pending['amount'])) {
        $meta['sendra_ledger_amount'] = (string) $pending['amount'];
    }
    if (empty($meta['source'])) {
        $meta['source'] = 'sendra_whatsapp';
    }
    if (!empty($pm['origin_whatsapp_phone_id'])) {
        $meta['origin_whatsapp_phone_id'] = (string) $pm['origin_whatsapp_phone_id'];
    }
    if (empty($meta['sendra_quoted_receive_ngn'])) {
        $quotedPool = $sellCtx['sendra_quoted_receive_ngn'] ?? $pm['sendra_quoted_receive_ngn'] ?? null;
        if (is_numeric($quotedPool) && (float) $quotedPool > 0) {
            $meta['sendra_quoted_receive_ngn'] = (string) round((float) $quotedPool, 2);
        } else {
            $quotedRaw = $pm['receive_ngn_display'] ?? null;
            if (is_string($quotedRaw) || is_numeric($quotedRaw)) {
                $quoted = (float) str_replace(',', '', (string) $quotedRaw);
                $requested = isset($meta['sendra_requested_ngn']) && is_numeric($meta['sendra_requested_ngn'])
                    ? (float) $meta['sendra_requested_ngn']
                    : (isset($pm['target_receive_ngn']) ? (float) $pm['target_receive_ngn'] : 0.0);
                if ($quoted > $requested + 0.01) {
                    $meta['sendra_quoted_receive_ngn'] = (string) round($quoted, 2);
                }
            }
        }
    }

    return $meta;
}

/**
 * Fill sendra_ledger_* from pending_transactions for receipt display (user-requested debit).
 *
 * @return array<string, string>
 */
function centiiv_sendra_merge_ledger_meta_from_pending(\PDO $aptosDb, string $orderLabel): array
{
    $pending = centiiv_sendra_lookup_pending_offramp_meta($aptosDb, $orderLabel);
    if ($pending === null) {
        return [];
    }

    $patch = [];
    $pm = $pending['meta'];
    $ledger = SendraCentiivPayoutReceiptFactory::resolveUserSellLedger(
        $pm,
        isset($pending['amount']) ? (string) $pending['amount'] : null,
        isset($pending['currency']) ? (string) $pending['currency'] : null
    );
    if ($ledger['asset'] !== '') {
        $patch['sendra_ledger_asset'] = $ledger['asset'];
    }
    if ($ledger['amount'] !== null) {
        $patch['sendra_ledger_amount'] = (string) $ledger['amount'];
    }
    if (isset($pm['centiiv_usdc_amount']) && is_numeric($pm['centiiv_usdc_amount']) && (float) $pm['centiiv_usdc_amount'] > 0) {
        $patch['centiiv_usdc_amount'] = (string) $pm['centiiv_usdc_amount'];
        $patch['centiiv_crypto_received'] = (string) $pm['centiiv_usdc_amount'];
        $patch['centiiv_crypto_asset'] = 'USDC';
    }

    return $patch;
}

/**
 * @param array<string, mixed> $event
 * @param array<string, string> $patch
 * @return array<string, mixed>
 */
function centiiv_sendra_patch_event_metadata(array $event, array $patch): array
{
    if ($patch === []) {
        return $event;
    }

    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        $data = [];
    }

    $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
    if (isset($meta['metadata']) && is_array($meta['metadata'])) {
        $meta['metadata'] = array_merge($meta['metadata'], $patch);
    }
    $data['metadata'] = array_merge($meta, $patch);
    $event['data'] = $data;

    return $event;
}

function centiiv_sendra_intermediary_disburse_tolerance_ngn(): float
{
    return SendraCentiivRampService::intermediaryPoolShortfallToleranceNgn();
}

/**
 * @return non-empty-string
 */
function centiiv_sendra_new_disburse_tracking_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Idempotency key for intermediary disburse tied to Centiiv order id.
 *
 * @return non-empty-string
 */
function centiiv_sendra_intermediary_order_cache_key(string $orderLabel): string
{
    $safe = preg_replace('/[^a-zA-Z0-9-]/', '', $orderLabel) ?? '';

    return 'order-' . ($safe !== '' ? $safe : bin2hex(random_bytes(8)));
}

/**
 * After Centiiv credits Sendra's intermediary NGN account, disburse the exact requested amount to the user.
 *
 * @param array<string, mixed> $event
 * @param array{user_phone: ?string, status: string, order_label: string, oneclick_correlation_id: string} $norm
 */
function centiiv_sendra_trigger_intermediary_disburse(
    array $event,
    array $norm,
    string $phone,
    string $orderLabel,
    \PDO $aptosDb,
    WhatsAppService $wa
): bool {
    $meta = centiiv_sendra_merge_intermediary_meta_from_pending($event, $aptosDb, $orderLabel);
    $requested = (float) ($meta['sendra_requested_ngn'] ?? 0);
    $userAcct = trim((string) ($meta['sendra_user_account_number'] ?? ''));
    $userCode = trim((string) ($meta['sendra_user_bank_code'] ?? ''));
    $userName = trim((string) ($meta['sendra_user_account_name'] ?? ''));
    $userBank = trim((string) ($meta['sendra_user_bank_name'] ?? ''));

    if ($requested <= 0 || strlen($userAcct) !== 10 || $userCode === '') {
        error_log('CENTIIV WEBHOOK sendra intermediary: missing user bank or requested NGN for ' . $orderLabel);

        return false;
    }

    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        $data = [];
    }

    $receivedNgn = centiiv_sendra_fulfillment_ngn_from_order($data);
    $quotedNgn = null;
    if (isset($meta['sendra_quoted_receive_ngn']) && is_numeric($meta['sendra_quoted_receive_ngn'])) {
        $quotedNgn = round((float) $meta['sendra_quoted_receive_ngn'], 2);
    }
    $cryptoAtCentiiv = SendraCentiivPayoutReceiptFactory::resolveCryptoReceivedAtCentiiv($meta, $data);

    if ($receivedNgn === null || !SendraCentiivRampService::intermediaryPoolCoversUserPayout($requested, $receivedNgn)) {
        $got = $receivedNgn !== null ? number_format($receivedNgn, 2, '.', ',') : '0.00';
        $need = SendraCentiivRampService::intermediaryPoolMinimumReceivedNgn($requested);
        error_log(
            'CENTIIV WEBHOOK sendra intermediary: pool shortfall order=' . $orderLabel
            . ' requested=' . $requested . ' received=' . ($receivedNgn ?? 'null')
            . ' pool_min=' . $need
        );
        // Notify once — Centiiv/Svix retries + poller must not re-spam the same shortfall.
        if (SendraCentiivRampService::claimPoolShortfallUserNotify($orderLabel)) {
            $wa->sendMessage(
                $phone,
                "⚠️ *Payout delayed*\n\n" .
                "We received *₦{$got}* in our settlement account but need at least *₦" . number_format($need, 0, '.', ',') . "* before sending *₦" .
                number_format($requested, 0, '.', ',') . "* to your bank.\n\n" .
                "Your crypto conversion completed. Our team is reviewing this — contact support@usesendra.com if you need help.\n\n" .
                "Order: `{$orderLabel}`"
            );
        }

        return false;
    }

    $existingOrder = SendraCentiivRampService::loadIntermediaryDisburseContextForOrder($orderLabel);
    if (is_array($existingOrder) && !empty($existingOrder['disburse_tracking_id'])) {
        error_log('CENTIIV WEBHOOK sendra intermediary: disburse already queued for order ' . $orderLabel);

        return true;
    }

    $trackingId = centiiv_sendra_new_disburse_tracking_uuid();

    $storedRef = centiiv_sendra_lookup_payout_reference($aptosDb, $orderLabel);
    $centiivOrderRate = SendraCentiivRampService::extractOfficialNgnRateFromOrder($data);
    $ctx = [
        'user_phone' => $phone,
        'centiiv_order_id' => $orderLabel,
        'requested_ngn' => $requested,
        'received_ngn' => $receivedNgn,
        'quoted_receive_ngn' => $quotedNgn,
        'centiiv_crypto_received' => $cryptoAtCentiiv['amount'] ?? null,
        'centiiv_crypto_asset' => $cryptoAtCentiiv['asset'] ?? (string) ($meta['sendra_ledger_asset'] ?? 'USDT'),
        'user_account_number' => $userAcct,
        'user_bank_code' => $userCode,
        'user_account_name' => $userName,
        'user_bank_name' => $userBank,
        'sendra_payout_reference' => $storedRef,
        'sendra_ledger_asset' => (string) ($meta['sendra_ledger_asset'] ?? 'USDT'),
        'sendra_ledger_amount' => (string) ($meta['sendra_ledger_amount'] ?? ''),
        'oneclick_correlation_id' => (string) ($norm['oneclick_correlation_id'] ?? ''),
        'disburse_tracking_id' => $trackingId,
        'centiiv_order_rate' => $centiivOrderRate,
    ];
    if (!empty($meta['origin_whatsapp_phone_id'])) {
        $ctx['origin_whatsapp_phone_id'] = (string) $meta['origin_whatsapp_phone_id'];
    }
    SendraCentiivRampService::cacheIntermediaryDisburseContextForOrder($orderLabel, $trackingId, $ctx);

    $agg = new SendraCentiivRampService();
    $res = $agg->disburseNgnToBank(
        $userAcct,
        $userCode,
        $requested,
        $trackingId,
        'Sendra WhatsApp payout ' . $phone
    );

    if (!$res['success']) {
        error_log(
            'CENTIIV WEBHOOK sendra intermediary disburse failed order=' . $orderLabel
            . ' err=' . ($res['error'] ?? 'unknown')
            . ' data=' . json_encode($res['data'] ?? [])
        );

        return false;
    }

    error_log('CENTIIV WEBHOOK sendra intermediary disburse queued tracking=' . $trackingId . ' order=' . $orderLabel);

    centiiv_sendra_send_intermediary_receipt_from_ctx($wa, $ctx);

    return true;
}

/**
 * Send intermediary bank payout receipt to WhatsApp.
 *
 * @param array<string, mixed> $ctx
 */
function centiiv_sendra_send_intermediary_receipt_from_ctx(WhatsAppService $wa, array $ctx, array $eventPayload = []): bool
{
    $phone = trim((string) ($ctx['user_phone'] ?? ''));
    if ($phone === '') {
        return false;
    }

    $trackingId = trim((string) ($ctx['disburse_tracking_id'] ?? $ctx['centiiv_order_id'] ?? ''));
    $requested = (float) ($ctx['requested_ngn'] ?? 0);
    $feeFields = SendraCentiivPayoutReceiptFactory::resolveIntermediaryReceiptFee($requested, $ctx);

    try {
        $receiptModel = [
            'amount_ngn' => $requested > 0 ? $requested : null,
            'transaction_fee_ngn' => $feeFields['transaction_fee_ngn'],
            'transaction_fee_crypto' => $feeFields['transaction_fee_crypto'],
            'transaction_fee_asset' => $feeFields['transaction_fee_asset'],
            'account_name' => (string) ($ctx['user_account_name'] ?? '—'),
            'account_number' => (string) ($ctx['user_account_number'] ?? '—'),
            'bank_name' => (string) ($ctx['user_bank_name'] ?? '—'),
            'withdraw_asset' => (string) ($ctx['sendra_ledger_asset'] ?? 'USDT'),
            'withdraw_amount' => is_numeric($ctx['sendra_ledger_amount'] ?? null) ? (float) $ctx['sendra_ledger_amount'] : null,
            'display_reference' => SendraCentiivPayoutReceiptFactory::resolveIntermediaryDisplayReference($ctx, $eventPayload),
            'transaction_id' => $trackingId !== '' ? $trackingId : null,
            'sender_name' => (string) (
                $ctx['sender_name']
                ?? SendraCentiivPayoutReceiptFactory::resolveReceiptSenderLabel(true)
            ),
            'completed_at' => SendraCentiivPayoutReceiptFactory::formatReceiptNow('M j, Y g:i A'),
            'conversion_rate' => SendraCentiivPayoutReceiptFactory::formatIntermediaryReceiptConversionRate($ctx),
        ];

        $receiptPath = SendraPayoutReceiptImageService::renderToTempFile($receiptModel);
        if ($receiptPath !== null && is_readable($receiptPath)) {
            $wa->sendImage($phone, $receiptPath, '✅ Sendra — bank payout receipt');
            @unlink($receiptPath);

            return true;
        }
    } catch (\Throwable $e) {
        error_log('CENTIIV WEBHOOK sendra intermediary receipt: ' . $e->getMessage());
    }

    return false;
}

/**
 * Send receipt when Centiiv reports intermediary disbursement complete.
 *
 * @param array<string, mixed> $event
 */
function centiiv_sendra_handle_intermediary_disbursement_webhook(
    array $event,
    string $accessToken,
    string $defaultPhoneId,
    \PDO $aptosDb
): bool {
    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        return false;
    }

    $meta = centiiv_sendra_extract_metadata($event);
    $dataMeta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

    $trackingId = trim((string) (
        $data['trackingId']
        ?? $data['tracking_id']
        ?? $dataMeta['trackingId']
        ?? $dataMeta['tracking_id']
        ?? $meta['trackingId']
        ?? ''
    ));
    if ($trackingId === '') {
        return false;
    }

    $status = strtolower(trim((string) ($data['status'] ?? $event['status'] ?? '')));
    if ($status !== '' && !centiiv_sendra_is_terminal_success($status)) {
        if (centiiv_sendra_is_terminal_failure($status)) {
            error_log('CENTIIV WEBHOOK sendra intermediary disburse failed tracking=' . $trackingId . ' status=' . $status);
        }

        return true;
    }

    $ctx = SendraCentiivRampService::loadIntermediaryDisburseContext($trackingId);
    if ($ctx === null) {
        return false;
    }

    $ctx['sender_name'] = SendraCentiivPayoutReceiptFactory::resolveReceiptSenderLabel(true, $data, $meta);
    SendraCentiivRampService::cacheIntermediaryDisburseContext($trackingId, $ctx);
    $orderIdForCtx = trim((string) ($ctx['centiiv_order_id'] ?? ''));
    if ($orderIdForCtx !== '') {
        SendraCentiivRampService::cacheIntermediaryDisburseContext(
            centiiv_sendra_intermediary_order_cache_key($orderIdForCtx),
            $ctx
        );
    }

    $phone = trim((string) ($ctx['user_phone'] ?? ''));
    if ($phone === '') {
        return false;
    }

    $lockKey = 'intermediary_disb_' . $trackingId;
    if (!centiiv_sendra_try_claim_terminal_notify($lockKey)) {
        return true;
    }

    $pendingMeta = null;
    $origin = trim((string) ($ctx['origin_whatsapp_phone_id'] ?? ''));
    if ($origin !== '') {
        $pendingMeta = ['origin_whatsapp_phone_id' => $origin];
    } else {
        $orderId = trim((string) ($ctx['centiiv_order_id'] ?? ''));
        if ($orderId !== '') {
            $pendingMeta = centiiv_sendra_pending_meta_for_order($aptosDb, $orderId);
        }
    }
    $wa = centiiv_sendra_whatsapp_for_user($accessToken, $defaultPhoneId, $aptosDb, $phone, $pendingMeta);

    try {
        if (!centiiv_sendra_send_intermediary_receipt_from_ctx($wa, $ctx, $data)) {
            centiiv_sendra_release_terminal_notify_lock($lockKey);

            return false;
        }
    } catch (\Throwable $e) {
        error_log('CENTIIV WEBHOOK sendra intermediary receipt: ' . $e->getMessage());
        centiiv_sendra_release_terminal_notify_lock($lockKey);

        return false;
    }

    return true;
}

/**
 * Sendra Aptos sell/buy — order status webhooks from api.centiiv.io (metadata.source = sendra_whatsapp).
 *
 * @param array<string, mixed> $event
 */
function centiiv_handle_sendra_ramp_webhook(array $event, string $accessToken, string $aptosPhoneId, \PDO $aptosDb): void
{
    $eventType = centiiv_webhook_event_type($event);
    if ($eventType === 'order.created') {
        SendraCentiivRampService::cacheOfframpOrderCreatedFromWebhook($event);
        SendraCentiivRampService::cacheOnrampOrderCreatedFromWebhook($event);
        centiiv_sendra_try_notify_buy_bank_on_order_created($event, $accessToken, $aptosPhoneId, $aptosDb);
    }

    $norm = centiiv_sendra_normalize_conversion_event($event);
    $phone = $norm['user_phone'];
    $status = $norm['status'];
    $orderLabel = $norm['order_label'];
    $oneclickRef = isset($norm['oneclick_correlation_id']) ? trim((string) $norm['oneclick_correlation_id']) : '';
    $refLine = $oneclickRef !== '' ? ("\nRef: `{$oneclickRef}`") : '';
    $isBuy = centiiv_sendra_event_is_buy($event, $norm);

    if ($phone === null || $phone === '') {
        error_log('CENTIIV WEBHOOK sendra: no user_phone in metadata; ack only');

        return;
    }

    $pendingMeta = centiiv_sendra_pending_meta_for_order($aptosDb, $orderLabel);
    $wa = centiiv_sendra_whatsapp_for_user($accessToken, $aptosPhoneId, $aptosDb, $phone, $pendingMeta);

    $statusLc = strtolower($status);
    if ($statusLc === '') {
        return;
    }

    if ($isBuy) {
        centiiv_sendra_try_submit_buy_oneclick_deposit($event, $aptosDb, $orderLabel);
    }

    // Intermediary exact-amount disburse completion → receipt only.
    if ($eventType === 'disbursement.status' && centiiv_sendra_handle_intermediary_disbursement_webhook($event, $accessToken, $aptosPhoneId, $aptosDb)) {
        return;
    }

    if (centiiv_sendra_is_terminal_success($statusLc)) {
        if (!centiiv_sendra_try_claim_terminal_notify($orderLabel . ($isBuy ? '_buy' : '_sell'))) {
            error_log('CENTIIV WEBHOOK sendra: duplicate terminal success skipped for ' . $orderLabel);

            return;
        }
        $notifyOk = false;
        try {
            if ($isBuy) {
                $data = $event['data'] ?? $event;
                if (is_object($data)) {
                    $data = (array) $data;
                }
                $meta = centiiv_sendra_extract_metadata($event);
                $pendingForBuy = is_array($pendingMeta) ? $pendingMeta : [];
                $mergedBuyMeta = array_merge($pendingForBuy, $meta);
                $stellarBuy = \App\Services\SendraIndexerDepositNotifyService::isNativeStellarOnrampMeta($mergedBuyMeta);
                $arcBuy = \App\Services\SendraIndexerDepositNotifyService::isNativeArcOnrampMeta($mergedBuyMeta);
                $solanaBuy = \App\Services\SendraIndexerDepositNotifyService::isDirectSolanaOnrampMeta($mergedBuyMeta);
                if ($solanaBuy) {
                    $asset = (string) ($meta['sendra_ledger_asset'] ?? $pendingForBuy['ledger_asset'] ?? 'USDC');
                    $recv = centiiv_sendra_buy_recv_amount_display($event, $aptosDb, $orderLabel);
                    $solAddr = trim((string) (
                        $meta['sendra_user_solana']
                        ?? $pendingForBuy['solana_destination']
                        ?? $pendingForBuy['user_solana']
                        ?? ''
                    ));
                    $solShort = $solAddr !== ''
                        ? (strlen($solAddr) > 12 ? (substr($solAddr, 0, 4) . '…' . substr($solAddr, -4)) : $solAddr)
                        : 'your Solana address';
                    $wa->sendMessage(
                        $phone,
                        "✅ *Naira payment received*\n\n" .
                        "Order `{$orderLabel}` — Sendra is sending *{$recv} {$asset}* on Solana to `{$solShort}`.\n\n" .
                        "Check that wallet; Sendra does not hold Solana for you."
                    );
                    $pendingRow = centiiv_sendra_find_onramp_pending($aptosDb, $orderLabel);
                    if (is_array($pendingRow)) {
                        $recvAmt = 0.0;
                        if (preg_match('/([\d.]+)/', (string) $recv, $mAmt) === 1) {
                            $recvAmt = (float) $mAmt[1];
                        }
                        \App\Services\SendraIndexerDepositNotifyService::completeBuyOnramp(
                            $aptosDb,
                            $wa,
                            $pendingRow,
                            $recvAmt,
                            $asset,
                            null,
                            null,
                            'solana_payout'
                        );
                    }
                } elseif (!$stellarBuy && !$arcBuy) {
                    $asset = (string) ($meta['sendra_ledger_asset'] ?? 'USDC');
                    $recv = centiiv_sendra_buy_recv_amount_display($event, $aptosDb, $orderLabel);
                    $msgRes = $wa->sendMessage(
                        $phone,
                        "✅ *Naira payment received*\n\n" .
                        "Order `{$orderLabel}` — we're converting to *{$recv} {$asset}* for your wallet.{$refLine}\n\n" .
                        "You'll get another message when crypto lands (usually a few minutes)."
                    );
                }
                centiiv_sendra_mark_onramp_paid($aptosDb, $orderLabel);
                centiiv_sendra_try_stellar_pool_sweep($event, $aptosDb, $phone, $recv ?? '', $orderLabel, $wa);
            } else {
                centiiv_sendra_record_offramp_outcome($aptosDb, $event, $orderLabel, $phone);

                if (centiiv_sendra_is_intermediary_offramp_event($event)) {
                    $disbOk = centiiv_sendra_trigger_intermediary_disburse($event, $norm, $phone, $orderLabel, $aptosDb, $wa);
                    // Keep terminal lock after pool-shortfall notify so retries do not re-enter.
                    $notifyOk = $disbOk || SendraCentiivRampService::poolShortfallUserNotifyClaimed($orderLabel);
                    if (!$disbOk) {
                        error_log('CENTIIV WEBHOOK sendra: intermediary disburse failed for order ' . $orderLabel);
                    }

                    return;
                }

                $receiptPath = null;
                try {
                    $storedRef = centiiv_sendra_lookup_payout_reference($aptosDb, $orderLabel);
                    $ledgerPatch = centiiv_sendra_merge_ledger_meta_from_pending($aptosDb, $orderLabel);
                    $receiptEvent = centiiv_sendra_patch_event_metadata($event, $ledgerPatch);
                    $receiptNorm = $norm;
                    if (!empty($ledgerPatch['sendra_ledger_asset'])) {
                        $receiptNorm['ledger_asset'] = $ledgerPatch['sendra_ledger_asset'];
                    }
                    if (!empty($ledgerPatch['sendra_ledger_amount'])) {
                        $receiptNorm['ledger_amount'] = $ledgerPatch['sendra_ledger_amount'];
                    }
                    $receiptModel = SendraCentiivPayoutReceiptFactory::build($receiptEvent, $receiptNorm, $storedRef);
                    $receiptPath = SendraPayoutReceiptImageService::renderToTempFile($receiptModel);
                } catch (\Throwable $e) {
                    error_log('CENTIIV WEBHOOK sendra receipt: ' . $e->getMessage());
                }
                if ($receiptPath !== null && is_string($receiptPath) && is_readable($receiptPath)) {
                    $imgRes = $wa->sendImage($phone, $receiptPath, '✅ Sendra — bank payout receipt');
                    $notifyOk = is_array($imgRes) && !empty($imgRes['success']);
                    if ($imgRes === false) {
                        error_log('CENTIIV WEBHOOK sendra: sendImage upload failed for ' . $phone);
                    } elseif (is_array($imgRes) && empty($imgRes['success'])) {
                        error_log('CENTIIV WEBHOOK sendra: sendImage API failed for ' . $phone);
                    }
                    @unlink($receiptPath);
                }
            }
            if (!$notifyOk && $isBuy) {
                error_log('CENTIIV WEBHOOK sendra: sendMessage failed for ' . $phone);
            }
        } finally {
            if (!$notifyOk) {
                centiiv_sendra_release_terminal_notify_lock($orderLabel . ($isBuy ? '_buy' : '_sell'));
            }
        }
    } elseif (centiiv_sendra_is_terminal_failure($statusLc)) {
        if ($isBuy) {
            $failLead = 'Buy order';
            $wa->sendMessage(
                $phone,
                "⚠️ *{$failLead} update*\n\n" .
                "Your buy order ({$orderLabel}) reported: *{$status}*.{$refLine}\n" .
                "If you already paid, contact support@usesendra.com with this message."
            );
            centiiv_sendra_mark_onramp_failed($aptosDb, $orderLabel);
        } else {
            error_log(
                'CENTIIV WEBHOOK sendra offramp terminal failure (no user notify): order='
                . $orderLabel . ' status=' . $status . ' phone=' . $phone
            );
        }
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
 * Handle Centiiv order status webhooks where `data` is only the order UUID string.
 *
 * @param array<string, mixed> $event
 */
function centiiv_try_handle_sendra_order_id_status_webhook(
    array $event,
    string $accessToken,
    string $aptosPhoneId,
    \PDO $aptosDb
): bool {
    $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
    if ($eventType === '' || !str_starts_with($eventType, 'order.')) {
        return false;
    }

    $rawData = $event['data'] ?? null;
    if (!is_string($rawData)) {
        return false;
    }
    $orderId = trim($rawData);
    if ($orderId === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $orderId)) {
        return false;
    }

    $agg = new SendraCentiivRampService();
    if (!$agg->isConfigured()) {
        error_log('CENTIIV WEBHOOK sendra order-id: SENDRA_CENTIIV_JWT not set; cannot enrich ' . $orderId);

        return false;
    }

    $res = $agg->getRequest($orderId);
    if (!$res['success']) {
        error_log('CENTIIV WEBHOOK sendra order-id: getRequest failed ' . $orderId . ' http=' . (string) ($res['http_code'] ?? 0));

        return false;
    }

    $order = centiiv_unwrap_get_request_data($res['data']);
    $synthetic = [
        'event_type' => $eventType,
        'timestamp' => $event['timestamp'] ?? null,
        'data' => $order,
    ];
    if (!centiiv_payload_is_sendra_ramp($synthetic)) {
        return false;
    }

    error_log('CENTIIV WEBHOOK sendra order-id: enriched ' . $eventType . ' for ' . $orderId);
    centiiv_handle_sendra_ramp_webhook($synthetic, $accessToken, $aptosPhoneId, $aptosDb);

    return true;
}

/**
 * Extract WhatsApp phone from Centiiv order description.
 * Matches "Sendra WhatsApp sell {phone}", "Sendra WhatsApp Stellar sell {phone}", and buy variants.
 */
function centiiv_sendra_phone_from_order_description(?string $desc): ?string
{
    if ($desc === null || $desc === '') {
        return null;
    }
    if (preg_match('/\bSendra\s+WhatsApp\s+(?:[A-Za-z]+\s+)?sell\s+(\d{10,20})\b/i', $desc, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/\bSendra\s+WhatsApp\s+(?:[A-Za-z]+\s+)?buy\s+(\d{10,20})\b/i', $desc, $m) === 1) {
        return $m[1];
    }

    return null;
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
    // - beneficiary.externalId like "sendra_wa_2348..." or "sendra_intermediary_ngn"
    // - description like "Sendra WhatsApp sell 2347..." / "Sendra WhatsApp Stellar sell 2347..."
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
        if (is_string($externalId) && (
            str_starts_with($externalId, 'sendra_wa_')
            || $externalId === 'sendra_intermediary_ngn'
            || str_starts_with($externalId, 'sendra_')
        )) {
            return true;
        }
    }
    $desc = $data['description'] ?? $event['description'] ?? null;
    if (centiiv_sendra_phone_from_order_description(is_string($desc) ? $desc : null) !== null) {
        return true;
    }

    if (($meta['sendra_ramp_kind'] ?? '') === 'buy') {
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
    // Order description is set by Sendra at create-order time from the WhatsApp sender — prefer over
    // beneficiary.externalId (Centiiv verify may attach a stale id for a reused NUBAN).
    // Supports "Sendra WhatsApp sell …" and "Sendra WhatsApp Stellar sell …".
    $desc = $data['description'] ?? $event['description'] ?? null;
    $phoneFromDesc = centiiv_sendra_phone_from_order_description(is_string($desc) ? $desc : null);
    if ($phoneFromDesc !== null && $phoneFromDesc !== '') {
        if ($phone === null || $phone === '' || $phone !== $phoneFromDesc) {
            if ($phone !== null && $phone !== '' && $phone !== $phoneFromDesc) {
                error_log(
                    'CENTIIV WEBHOOK sendra: notify phone from description overrides metadata/externalId; '
                    . json_encode(['metadata_phone' => $phone, 'description_phone' => $phoneFromDesc, 'order' => $data['id'] ?? null])
                );
            }
            $phone = $phoneFromDesc;
        }
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

/**
 * @param array<string, mixed> $event
 * @param array{user_phone: ?string, status: string, order_label: string, oneclick_correlation_id: string} $norm
 */
function centiiv_sendra_event_is_buy(array $event, array $norm): bool
{
    $meta = centiiv_sendra_extract_metadata($event);
    if (($meta['sendra_ramp_kind'] ?? '') === 'buy') {
        return true;
    }
    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    $type = strtoupper((string) ($data['type'] ?? ''));
    if ($type === 'ONRAMP') {
        return true;
    }
    $from = strtoupper((string) ($data['fromAsset'] ?? ''));
    $to = strtoupper((string) ($data['toAsset'] ?? ''));
    if ($from === 'NGN' && in_array($to, ['USDC', 'USDT', 'APT'], true)) {
        return true;
    }
    $desc = (string) ($data['description'] ?? $event['description'] ?? '');

    return preg_match('/\bSendra\s+WhatsApp\s+buy\s+/i', $desc) === 1;
}

/**
 * USDC amount to show on buy "Naira payment received" (Centiiv webhook often omits sendra_ledger_amount).
 *
 * @param array<string, mixed> $event
 */
function centiiv_sendra_buy_recv_amount_display(array $event, \PDO $aptosDb, string $orderLabel): string
{
    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        return '—';
    }

    $fulfillments = $data['fulfillments'] ?? null;
    if (is_array($fulfillments)) {
        foreach ($fulfillments as $fulfillment) {
            if (!is_array($fulfillment)) {
                continue;
            }
            $amt = $fulfillment['amount'] ?? null;
            if ($amt !== null && is_numeric($amt) && (float) $amt > 0) {
                return (string) $amt;
            }
        }
    }

    $row = centiiv_sendra_find_onramp_pending($aptosDb, $orderLabel);
    if ($row !== null) {
        if (isset($row['amount']) && is_numeric($row['amount']) && (float) $row['amount'] > 0) {
            return (string) $row['amount'];
        }
        $pendingMeta = json_decode((string) ($row['human_description'] ?? ''), true);
        if (is_array($pendingMeta)) {
            if (!empty($pendingMeta['receive_crypto_display'])) {
                return (string) $pendingMeta['receive_crypto_display'];
            }
            if (!empty($pendingMeta['sendra_ledger_amount'])) {
                return (string) $pendingMeta['sendra_ledger_amount'];
            }
        }
    }

    $meta = centiiv_sendra_extract_metadata($event);
    if (!empty($meta['sendra_ledger_amount'])) {
        return (string) $meta['sendra_ledger_amount'];
    }

    return '—';
}

/**
 * When Centiiv sends Stellar USDC to 1Click, notify 1Click with the on-chain tx hash.
 *
 * @param array<string, mixed> $event
 */
function centiiv_sendra_try_submit_buy_oneclick_deposit(array $event, \PDO $aptosDb, string $orderLabel): void
{
    $row = centiiv_sendra_find_onramp_pending($aptosDb, $orderLabel);
    $pendingMeta = [];
    if ($row !== null) {
        $decoded = json_decode((string) ($row['human_description'] ?? ''), true);
        if (is_array($decoded)) {
            $pendingMeta = $decoded;
        }
    }
    $eventMeta = centiiv_sendra_extract_metadata($event);
    if (\App\Services\SendraIndexerDepositNotifyService::skipsOneClickOnrampMeta(array_merge($pendingMeta, $eventMeta))) {
        return;
    }

    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        return;
    }

    $outbound = SendraCentiivRampService::extractOutboundCryptoTx($data);
    if ($outbound === null || empty($outbound['tx_hash'])) {
        return;
    }

    if ($row === null) {
        return;
    }

    $meta = $pendingMeta;
    if ($meta === []) {
        return;
    }
    if (!empty($meta['oneclick_deposit_submitted'])) {
        return;
    }

    $deposit = (string) ($meta['sendra_oneclick_deposit_address'] ?? $meta['sendra_oneclick_deposit_stellar'] ?? $row['recipient_address'] ?? '');
    if ($deposit === '') {
        return;
    }
    $memo = isset($meta['sendra_oneclick_deposit_memo']) && is_string($meta['sendra_oneclick_deposit_memo'])
        ? $meta['sendra_oneclick_deposit_memo'] : null;

    $oneClick = new SendraOneClickService();
    if (!$oneClick->isConfigured()) {
        return;
    }

    $submit = $oneClick->submitDeposit($deposit, (string) $outbound['tx_hash'], $memo);
    error_log('CENTIIV WEBHOOK sendra buy 1Click submitDeposit: ' . json_encode([
        'order' => $orderLabel,
        'ok' => (bool) ($submit['success'] ?? false),
        'error' => $submit['error'] ?? null,
    ]));

    $patch = array_merge($meta, [
        'oneclick_deposit_submitted' => true,
        'centiiv_stellar_payout_tx' => $outbound['tx_hash'],
        'need_oneclick_poll' => true,
    ]);
    $u = $aptosDb->prepare('UPDATE pending_transactions SET human_description = ?, status = ? WHERE id = ?');
    $u->execute([json_encode($patch, JSON_UNESCAPED_UNICODE), 'confirmed', (string) $row['id']]);
}

/**
 * When Centiiv creates the buy order before HTTP returns, push virtual bank details over WhatsApp.
 *
 * @param array<string, mixed> $event
 */
function centiiv_sendra_try_notify_buy_bank_on_order_created(
    array $event,
    string $accessToken,
    string $aptosPhoneId,
    \PDO $aptosDb
): void {
    try {
        $norm = centiiv_sendra_normalize_conversion_event($event);
        if (!centiiv_sendra_event_is_buy($event, $norm)) {
            return;
        }

        $phone = $norm['user_phone'] ?? null;
        if ($phone === null || $phone === '') {
            error_log('CENTIIV WEBHOOK sendra buy: skip bank notify — no phone');

            return;
        }

        $data = $event['data'] ?? $event;
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (!is_array($data)) {
            error_log('CENTIIV WEBHOOK sendra buy: skip bank notify — no data');

            return;
        }

        $orderId = (string) ($data['id'] ?? $norm['order_label'] ?? '');
        if ($orderId === '') {
            error_log('CENTIIV WEBHOOK sendra buy: skip bank notify — no order id');

            return;
        }

        $bank = SendraCentiivRampService::extractVirtualBankAccount($data);
        if ($bank === null) {
            error_log('CENTIIV WEBHOOK sendra buy: skip bank notify — no virtual bank on order ' . $orderId);

            return;
        }

        $stmt = $aptosDb->prepare("
        SELECT id FROM pending_transactions
        WHERE tx_type = 'onramp_oneclick_centiiv'
          AND user_phone = :phone
          AND (
            human_description LIKE :like_id
            OR human_description LIKE :like_id2
          )
        LIMIT 1
    ");
    $stmt->execute([
        'phone' => $phone,
        'like_id' => '%"centiiv_order_id":"' . $orderId . '"%',
        'like_id2' => '%"centiiv_order_id": "' . $orderId . '"%',
    ]);
        if ($stmt->fetch(\PDO::FETCH_ASSOC) !== false) {
            error_log('CENTIIV WEBHOOK sendra buy: skip bank notify — pending already exists for ' . $orderId);

            return;
        }

        $desc = (string) ($data['description'] ?? '');
    $oneclickCorrelation = null;
    $memo = null;
    if (preg_match('/\(1Click\s+([0-9a-f-]{36})\)/i', $desc, $m) === 1) {
        $oneclickCorrelation = $m[1];
    }
    if (preg_match('/\bmemo:(\d+)\b/i', $desc, $m) === 1) {
        $memo = $m[1];
    }
    $beneficiary = $data['beneficiary'] ?? null;
    $stellarDest = is_array($beneficiary) ? (string) ($beneficiary['address'] ?? '') : '';

    $payNgn = (float) ($data['amount'] ?? 0);
    $eventMeta = centiiv_sendra_extract_metadata($event);
    $destChain = strtolower((string) ($eventMeta['sendra_dest_chain'] ?? ''));
    $solDest = trim((string) ($eventMeta['sendra_user_solana'] ?? ''));
    $skipOneClick = \App\Services\SendraIndexerDepositNotifyService::skipsOneClickOnrampMeta([
        'dest_chain' => $destChain,
        'sendra_dest_chain' => $destChain,
    ]);
    $meta = [
        'kind' => 'oneclick_centiiv_onramp',
        'centiiv_order_id' => $orderId,
        'pay_ngn' => $payNgn,
        'pay_ngn_display' => number_format($payNgn, 2, '.', ','),
        'ledger_asset' => (string) ($eventMeta['sendra_ledger_asset'] ?? 'USDC'),
        'user_aptos' => null,
        'sendra_oneclick_correlation_id' => $oneclickCorrelation,
        'sendra_oneclick_deposit_stellar' => $stellarDest !== '' ? $stellarDest : null,
        'sendra_oneclick_deposit_address' => $stellarDest !== '' ? $stellarDest : null,
        'sendra_oneclick_deposit_memo' => $memo,
        'need_oneclick_poll' => !$skipOneClick,
        'dest_chain' => $destChain !== '' ? $destChain : 'aptos',
        'sendra_dest_chain' => $destChain !== '' ? $destChain : null,
        'solana_destination' => $solDest !== '' ? $solDest : null,
        'user_solana' => $solDest !== '' ? $solDest : null,
        'virtual_bank' => $bank,
        'bank_notified_via_webhook' => true,
    ];

    $userStmt = $aptosDb->prepare('SELECT aptos_address FROM users WHERE phone_number = :phone LIMIT 1');
    $userStmt->execute(['phone' => $phone]);
    $userRow = $userStmt->fetch(\PDO::FETCH_ASSOC);
    if (is_array($userRow) && !empty($userRow['aptos_address'])) {
        $meta['user_aptos'] = (string) $userRow['aptos_address'];
    }

    $pendingId = bin2hex(random_bytes(16));
    $ins = $aptosDb->prepare("
        INSERT INTO pending_transactions
            (id, user_phone, tx_type, amount, currency, recipient_address, human_description, expires_at, status, created_at)
        VALUES
            (:id, :phone, 'onramp_oneclick_centiiv', :amount, 'USDC', :recipient, :meta, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending', NOW())
    ");
    $ins->execute([
        'id' => $pendingId,
        'phone' => $phone,
        'amount' => '0',
        'recipient' => $solDest !== '' ? $solDest : ($stellarDest !== '' ? $stellarDest : ''),
        'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
    ]);

    $clearCtx = $aptosDb->prepare("
        UPDATE users SET chat_context = NULL
        WHERE phone_number = :phone AND chat_context LIKE '%\"flow\":\"buy\"%'
    ");
    $clearCtx->execute(['phone' => $phone]);

    $destLine = 'your Sendra wallet';
    if ($destChain === 'solana') {
        $destLine = $solDest !== ''
            ? ('your Solana wallet (`' . (strlen($solDest) > 12 ? (substr($solDest, 0, 4) . '…' . substr($solDest, -4)) : $solDest) . '`)')
            : 'your Solana wallet';
    } elseif ($destChain === 'stellar') {
        $destLine = 'your Sendra Stellar wallet';
    }
    $wa = new WhatsAppService($accessToken, $aptosPhoneId);
    $payDisplay = number_format($payNgn, 0, '.', ',');
    $wa->sendMessage(
        $phone,
        "🏦 *Pay to complete your buy*\n\n" .
        "Transfer *exactly ₦{$payDisplay}* to:\n\n" .
        "*{$bank['account_name']}*\n" .
        "`{$bank['account_number']}`\n" .
        "*{$bank['bank_name']}*\n\n" .
        "Order: `{$orderId}`\n" .
        "You receive crypto → {$destLine}\n\n" .
        "⏱ Complete payment within *30 minutes*. We’ll notify you when crypto lands."
    );

        error_log('CENTIIV WEBHOOK sendra buy: bank details sent for order ' . $orderId . ' phone ' . $phone);
    } catch (\Throwable $e) {
        error_log('CENTIIV WEBHOOK sendra buy: bank notify failed — ' . $e->getMessage());
    }
}

function centiiv_sendra_find_onramp_pending(\PDO $aptosDb, string $orderLabel): ?array
{
    $orderLabel = trim($orderLabel);
    if ($orderLabel === '') {
        return null;
    }
    $stmt = $aptosDb->query("
        SELECT id, user_phone, recipient_address, human_description, status
        FROM pending_transactions
        WHERE tx_type = 'onramp_oneclick_centiiv'
          AND status IN ('pending', 'confirmed')
          AND expires_at > NOW()
    ");
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $meta = json_decode((string) ($row['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            continue;
        }
        $oid = (string) ($meta['centiiv_order_id'] ?? '');
        if ($oid === $orderLabel) {
            return $row;
        }
    }

    return null;
}

function centiiv_sendra_mark_onramp_paid(\PDO $aptosDb, string $orderLabel): void
{
    $row = centiiv_sendra_find_onramp_pending($aptosDb, $orderLabel);
    if ($row === null) {
        return;
    }
    $meta = json_decode((string) ($row['human_description'] ?? ''), true);
    if (!is_array($meta)) {
        $meta = [];
    }
    $meta['centiiv_paid_at'] = gmdate('c');
    $stellarBuy = \App\Services\SendraIndexerDepositNotifyService::skipsOneClickOnrampMeta($meta);
    if ($stellarBuy) {
        $meta['need_oneclick_poll'] = false;
    } else {
        $meta['need_oneclick_poll'] = true;
    }
    $u = $aptosDb->prepare('UPDATE pending_transactions SET human_description = ?, status = ? WHERE id = ?');
    $u->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), 'confirmed', (string) $row['id']]);
}

function centiiv_sendra_mark_onramp_failed(\PDO $aptosDb, string $orderLabel): void
{
    $row = centiiv_sendra_find_onramp_pending($aptosDb, $orderLabel);
    if ($row === null) {
        return;
    }
    $u = $aptosDb->prepare("UPDATE pending_transactions SET status = 'failed' WHERE id = ?");
    $u->execute([(string) $row['id']]);
}

/**
 * Logistics rider wallet withdrawal — Centiiv conversion / settlement webhooks.
 *
 * @param array<string, mixed> $event
 * @param array<string, mixed> $meta
 */
function centiiv_handle_logistics_rider_withdraw_webhook(
    array $event,
    array $meta,
    string $phone,
    string $orderLabel,
    WhatsAppService $wa,
    \PDO $db,
    LogisticsDriver $driverModel
): void {
    if ($phone === '') {
        $driverId = (int) ($meta['driver_id'] ?? 0);
        if ($driverId > 0) {
            $driver = $driverModel->findById($driverId);
            $phone = is_array($driver) ? trim((string) ($driver['phone'] ?? '')) : '';
        }
    }
    if ($phone === '') {
        error_log('CENTIIV WEBHOOK logistics rider: no phone; ack only');

        return;
    }

    $data = $event['data'] ?? $event;
    if (is_object($data)) {
        $data = (array) $data;
    }
    if (!is_array($data)) {
        $data = [];
    }

    $status = (string) ($data['status'] ?? $data['orderStatus'] ?? $event['status'] ?? '');
    $statusLc = strtolower($status);
    if ($statusLc === '') {
        return;
    }

    $reference = trim((string) ($meta['reference'] ?? ''));
    $wallet = new RiderWalletService($db, $driverModel, new RiderWalletTransaction($db));

    if (centiiv_sendra_is_terminal_success($statusLc)) {
        if ($reference !== '') {
            $wallet->completeWithdraw($reference);
        }
        $wa->sendMessage(
            $phone,
            "✅ *Withdrawal complete*\n\n"
            . 'Your bank payout has been processed. Type *balance* to see your wallet.'
        );

        return;
    }

    if (centiiv_sendra_is_terminal_failure($statusLc)) {
        if ($reference !== '') {
            $wallet->releaseWithdrawReserve($reference);
        }
        $wa->sendMessage(
            $phone,
            "⚠️ *Withdrawal failed*\n\n"
            . "Order `{$orderLabel}` could not be completed. Your wallet was refunded. Try *withdraw* again or contact support."
        );
    }
}

/**
 * After Centiiv pays the shared Sendra G + memo, move USDC to the user's C wallet.
 */
function centiiv_sendra_try_stellar_pool_sweep(
    array $event,
    \PDO $aptosDb,
    string $phone,
    string $recvDisplay,
    string $orderLabel,
    \App\Services\WhatsAppService $wa
): void {
    $meta = centiiv_sendra_extract_metadata($event);
    $pendingMeta = centiiv_sendra_pending_meta_for_order($aptosDb, $orderLabel);
    if (!is_array($pendingMeta)) {
        $pendingMeta = [];
    }
    $dest = strtolower((string) ($meta['sendra_dest_chain'] ?? $pendingMeta['dest_chain'] ?? ''));
    if ($dest !== 'stellar') {
        return;
    }
    // Do not sweep by quote amount: pool USDC is fungible. Horizon memo indexer
    // credits the exact inbound payment to the matching C wallet (1:1).
    try {
        $stats = (new \App\Services\SendraStellarPoolIndexerService($aptosDb, $wa))->run();
        error_log('CENTIIV WEBHOOK stellar pool index order=' . $orderLabel . ' ' . json_encode($stats));
    } catch (\Throwable $e) {
        error_log('CENTIIV WEBHOOK stellar pool index failed order=' . $orderLabel . ' ' . $e->getMessage());
    }
}
