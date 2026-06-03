<?php

/**
 * Poll 1Click for Sendra buys where Centiiv paid Stellar USDC but Aptos credit not yet confirmed.
 *
 * Crontab example (every minute):
 *   * * * * * php /var/www/html/coinectra/src/scripts/cron_oneclick_onramp_poll.php >> /var/log/oneclick_onramp_poll.log 2>&1
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '';
$bot = $bots[$aptosPhoneId] ?? null;
if ($bot === null) {
    fwrite(STDERR, "Unknown WHATSAPP_APTOS_PHONE_NUMBER_ID\n");
    exit(1);
}

$db = Database::getInstance($bot['db_config'], false)->getConnection();
$access = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';

$oneClick = new \App\Services\SendraOneClickService();
if (!$oneClick->isConfigured()) {
    echo "SENDRA_ONECLICK_JWT not set; nothing to do.\n";
    exit(0);
}

$wa = new \App\Services\WhatsAppService($access, $aptosPhoneId);

$stmt = $db->query("
    SELECT id, user_phone, recipient_address, human_description, amount, currency, expires_at
    FROM pending_transactions
    WHERE tx_type = 'onramp_oneclick_centiiv'
      AND status IN ('awaiting_payment', 'confirmed')
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $meta = json_decode((string) ($row['human_description'] ?? ''), true);
    if (!is_array($meta)) {
        continue;
    }
    if (empty($meta['need_oneclick_poll'])) {
        continue;
    }
    if (!empty($meta['oneclick_onramp_user_notified'])) {
        continue;
    }

    $deposit = (string) ($meta['sendra_oneclick_deposit_stellar'] ?? $row['recipient_address'] ?? '');
    if ($deposit === '') {
        continue;
    }
    $memo = isset($meta['sendra_oneclick_deposit_memo']) && is_string($meta['sendra_oneclick_deposit_memo']) && $meta['sendra_oneclick_deposit_memo'] !== ''
        ? $meta['sendra_oneclick_deposit_memo'] : null;

    $st = $oneClick->getStatus($deposit, $memo);
    if (!$st['success']) {
        continue;
    }

    $code = \App\Services\SendraOneClickService::parseStatus($st['data']);
    $phone = (string) ($row['user_phone'] ?? '');
    $correlation = (string) ($meta['sendra_oneclick_correlation_id'] ?? '');
    $asset = (string) ($row['currency'] ?? 'USDC');
    $amount = (string) ($row['amount'] ?? '');

    if ($code === 'SUCCESS') {
        $merge = json_encode(array_merge($meta, [
            'need_oneclick_poll' => false,
            'oneclick_swap_status' => 'SUCCESS',
            'oneclick_onramp_user_notified' => true,
        ]), JSON_UNESCAPED_UNICODE);
        $u = $db->prepare('UPDATE pending_transactions SET human_description = ?, status = ? WHERE id = ?');
        $u->execute([$merge, 'completed', $row['id']]);

        if ($phone !== '') {
            $wa->sendMessage(
                $phone,
                "✅ *Crypto received*\n\n" .
                "*{$amount} {$asset}* should now be in your Sendra wallet.\n" .
                ($correlation !== '' ? "Ref: `{$correlation}`\n\n" : "\n") .
                'Type *balance* to check.'
            );
        }
        echo "SUCCESS notified id={$row['id']}\n";

        continue;
    }

    if (in_array($code, ['FAILED', 'REFUNDED'], true)) {
        $merge = json_encode(array_merge($meta, [
            'need_oneclick_poll' => false,
            'oneclick_swap_status' => $code,
            'oneclick_onramp_user_notified' => true,
        ]), JSON_UNESCAPED_UNICODE);
        $u = $db->prepare('UPDATE pending_transactions SET human_description = ?, status = ? WHERE id = ?');
        $u->execute([$merge, 'failed', $row['id']]);

        if ($phone !== '') {
            $wa->sendMessage(
                $phone,
                "⚠️ *Buy update*\n\n" .
                "1Click reported: *{$code}* while converting to Aptos.\n" .
                ($correlation !== '' ? "Ref: `{$correlation}`\n\n" : '') .
                'Contact support@usesendra.com if Naira was already debited.'
            );
        }
        echo "terminal {$code} id={$row['id']}\n";
    }
}

echo 'Done. Rows scanned: ' . count($rows) . "\n";
