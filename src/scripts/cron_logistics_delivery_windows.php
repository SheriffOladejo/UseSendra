<?php

/**
 * Run every minute via crontab, e.g.:
 * * * * * php /var/www/html/coinectra/src/scripts/cron_logistics_delivery_windows.php >> /var/log/sendra-logistics-cron.log 2>&1
 *
 * - Expires stale driver_payment_pending → searching_driver, notifies rider + sender
 * - Closes expired bidding window (searching_driver + bidding_expires_at passed, no assignment)
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';

use App\Models\DeliveryRequest;
use App\Models\LogisticsDriver;
use App\Models\LogisticsUser;
use App\Services\WhatsAppMessagingWindow;
use App\Services\WhatsAppService;

$logisticsPhoneId = $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID') ?: '1097784993409171';

$db = Database::getInstance($bots[$logisticsPhoneId]['db_config'], false)->getConnection();

$accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$wa = new WhatsAppService($accessToken, $logisticsPhoneId);
$deliveryRequestModel = new DeliveryRequest($db);
$driverModel = new LogisticsDriver($db);
$userModel = new LogisticsUser($db);

$templPaymentTimeoutRider = trim((string) ($_ENV['LOGISTICS_WA_TEMPLATE_PAYMENT_TIMEOUT_RIDER'] ?? getenv('LOGISTICS_WA_TEMPLATE_PAYMENT_TIMEOUT_RIDER') ?: ''));
$templPaymentTimeoutSender = trim((string) ($_ENV['LOGISTICS_WA_TEMPLATE_PAYMENT_TIMEOUT_SENDER'] ?? getenv('LOGISTICS_WA_TEMPLATE_PAYMENT_TIMEOUT_SENDER') ?: ''));
$templSearchEndedSender = trim((string) ($_ENV['LOGISTICS_WA_TEMPLATE_SEARCH_ENDED_SENDER'] ?? getenv('LOGISTICS_WA_TEMPLATE_SEARCH_ENDED_SENDER') ?: ''));

$now = date('Y-m-d H:i:s');

// 1) Payment reservations timed out
$stmt = $db->query("
    SELECT * FROM delivery_requests
    WHERE status = 'driver_payment_pending'
    AND reservation_expires_at IS NOT NULL
    AND reservation_expires_at < " . $db->quote($now) . "
");
$stale = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($stale as $row) {
    $id = (int) $row['id'];
    $deliveryRequestModel->update($id, [
        'status' => 'searching_driver',
        'reserved_driver_id' => null,
        'reservation_expires_at' => null,
        'acceptance_payment_reference' => null,
    ]);

    $driver = !empty($row['reserved_driver_id']) ? $driverModel->findById((int) $row['reserved_driver_id']) : null;
    if ($driver) {
        $body = "⌛ Job #{$id}: confirmation window closed. The job is open for other riders again.";
        $last = $driver['last_message_at'] ?? null;
        if (WhatsAppMessagingWindow::isWithinCustomerCareWindow(is_string($last) ? $last : null)) {
            $wa->sendMessage($driver['phone'], $body);
        } elseif ($templPaymentTimeoutRider !== '') {
            $wa->sendTemplate($driver['phone'], $templPaymentTimeoutRider, [(string) $id]);
        } else {
            error_log("cron_logistics_delivery_windows: outside WA window for rider {$driver['phone']}; set LOGISTICS_WA_TEMPLATE_PAYMENT_TIMEOUT_RIDER or skip");
        }
    }

    $sender = !empty($row['user_id']) ? $userModel->findById((int) $row['user_id']) : null;
    if ($sender) {
        $body = "⌛ We couldn't confirm a rider in time for job #{$id}. Still searching — you'll get a message when someone is locked in.";
        $last = senderLastWhatsAppTouch($db, (string) $sender['phone']);
        if (WhatsAppMessagingWindow::isWithinCustomerCareWindow($last)) {
            $wa->sendMessage($sender['phone'], $body);
        } elseif ($templPaymentTimeoutSender !== '') {
            $wa->sendTemplate($sender['phone'], $templPaymentTimeoutSender, [(string) $id]);
        } else {
            error_log("cron_logistics_delivery_windows: outside WA window for sender {$sender['phone']}; set LOGISTICS_WA_TEMPLATE_PAYMENT_TIMEOUT_SENDER");
        }
    }
}

// 2) Bidding / search window ended with no assignment
$stmt = $db->query("
    SELECT * FROM delivery_requests
    WHERE status = 'searching_driver'
    AND bidding_expires_at IS NOT NULL
    AND bidding_expires_at < " . $db->quote($now) . "
    AND (reserved_driver_id IS NULL OR reserved_driver_id = 0)
");
$expiredSearch = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($expiredSearch as $row) {
    $id = (int) $row['id'];
    $deliveryRequestModel->update($id, [
        'bidding_closed' => 1,
        'status' => 'awaiting_rebroadcast',
    ]);

    $sender = !empty($row['user_id']) ? $userModel->findById((int) $row['user_id']) : null;
    if ($sender) {
        $n = (int) ($row['drivers_notified_count'] ?? 0);
        $hint = $n > 0
            ? " {$n} rider(s) were pinged."
            : ' No riders were online nearby.';
        $body = "🔍 Job #{$id}: first search ended.{$hint}\n\nPing riders again or cancel this request?";
        $last = senderLastWhatsAppTouch($db, (string) $sender['phone']);
        if (WhatsAppMessagingWindow::isWithinCustomerCareWindow($last)) {
            $wa->sendButtonMessage(
                $sender['phone'],
                $body,
                [
                    "rebroadcast_{$id}" => '🔁 Ping again',
                    "cancel_job_{$id}" => 'Cancel',
                ]
            );
        } elseif ($templSearchEndedSender !== '') {
            $wa->sendTemplate($sender['phone'], $templSearchEndedSender, [(string) $id, trim($hint)]);
        } else {
            error_log("cron_logistics_delivery_windows: outside WA window for sender {$sender['phone']} (search ended); set LOGISTICS_WA_TEMPLATE_SEARCH_ENDED_SENDER");
        }
    }
}

echo 'OK ' . count($stale) . " payment resets, " . count($expiredSearch) . " bidding windows → awaiting rebroadcast\n";

/**
 * Best-effort last activity for logistics senders (users + session row).
 */
function senderLastWhatsAppTouch(\PDO $db, string $phone): ?string
{
    if ($phone === '') {
        return null;
    }
    try {
        $stmt = $db->prepare('
            SELECT u.updated_at AS uu, ws.updated_at AS wsu
            FROM users u
            LEFT JOIN whatsapp_sessions ws ON ws.phone_number = u.phone
            WHERE u.phone = ?
            LIMIT 1
        ');
        $stmt->execute([$phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $uu = $row['uu'] ?? null;
        $wsu = $row['wsu'] ?? null;
        $a = is_string($uu) && $uu !== '' ? strtotime($uu) : false;
        $b = is_string($wsu) && $wsu !== '' ? strtotime($wsu) : false;
        if ($a !== false && $b !== false) {
            return $a >= $b ? (string) $uu : (string) $wsu;
        }
        if ($a !== false) {
            return (string) $uu;
        }
        if ($b !== false) {
            return (string) $wsu;
        }
    } catch (\Throwable $e) {
        error_log('senderLastWhatsAppTouch: ' . $e->getMessage());
    }

    return null;
}
