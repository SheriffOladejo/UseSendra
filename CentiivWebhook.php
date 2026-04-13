<?php

/**
 * Centiiv → Svix webhooks for logistics driver acceptance (Direct Pay).
 *
 * Handles `payment.successful` as documented for Centiiv on Svix:
 * @see https://www.svix.com/event-types/eu/org_2yieL70Sd2Iom1IBeqNlEWvzRoX/#disbursement.status
 *
 * Set CENTIIV_WEBHOOK_SECRET to your Svix endpoint signing secret (whsec_…).
 * Centiiv webhook consumption follows Svix: https://docs.svix.com/receiving/introduction
 *
 * IMPORTANT: Endpoint URL must be this file (e.g. …/CentiivWebhook.php), NOT PaystackWebhook.php
 * (Paystack returns 401 Invalid signature for Svix-signed Centiiv payloads).
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
use App\Services\SvixWebhookVerifier;
use App\Services\WhatsAppService;

$logisticsPhoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';

$db = Database::getInstance($bots[$logisticsPhoneId]['db_config'], false)->getConnection();

$accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$deliveryRequestModel = new DeliveryRequest($db);
$driverModel = new LogisticsDriver($db);
$userModel = new LogisticsUser($db);
$whatsappService = new WhatsAppService($accessToken, $logisticsPhoneId);

$payload = (string) @file_get_contents('php://input');
$event = json_decode($payload, true);

error_log('CENTIIV WEBHOOK: ' . $payload);

$webhookSecret = trim((string) ($_ENV['CENTIIV_WEBHOOK_SECRET'] ?? getenv('CENTIIV_WEBHOOK_SECRET') ?: ''));
if ($webhookSecret !== '') {
    if (str_starts_with($webhookSecret, 'whsec_')) {
        if (!SvixWebhookVerifier::verify($payload, $webhookSecret)) {
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
        $expected = hash_hmac('sha256', $payload, $webhookSecret);
        if ($sig === '' || !hash_equals($expected, $sig)) {
            http_response_code(401);
            exit('Invalid signature');
        }
    }
}

if (!is_array($event)) {
    http_response_code(200);
    exit('OK');
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
