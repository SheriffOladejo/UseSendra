<?php

/**
 * 1Click bridge status for Sendra buys (Stellar → Aptos).
 *
 * User WhatsApp for Aptos credit is handled by the Aptos indexer poller → webhook deposit
 * ({@see SendraIndexerDepositNotifyService}). This cron only:
 *   - Marks DB when 1Click reports SUCCESS (no user message)
 *   - Notifies on INCOMPLETE_DEPOSIT / FAILED / REFUNDED
 *
 * Disable entirely: SENDRA_ONECLICK_ONRAMP_POLL=0
 *
 * Crontab (optional, every 2–5 min for bridge failures only):
 *   * * * * * php /var/www/html/coinectra/src/scripts/cron_oneclick_onramp_poll.php >> /var/log/oneclick_onramp_poll.log 2>&1
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$enabled = strtolower(trim((string) ($_ENV['SENDRA_ONECLICK_ONRAMP_POLL'] ?? getenv('SENDRA_ONECLICK_ONRAMP_POLL') ?: '1')));
if (in_array($enabled, ['0', 'false', 'off', 'no'], true)) {
    echo "SENDRA_ONECLICK_ONRAMP_POLL disabled; exit.\n";
    exit(0);
}

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
    SELECT id, user_phone, recipient_address, human_description, amount, currency, expires_at, created_at
    FROM pending_transactions
    WHERE tx_type = 'onramp_oneclick_centiiv'
      AND status IN ('pending', 'confirmed')
    ORDER BY created_at ASC
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
    if (!empty($meta['indexer_deposit_notified'])) {
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

    if ($code === 'SUCCESS') {
        $merge = json_encode(array_merge($meta, [
            'need_oneclick_poll' => false,
            'oneclick_swap_status' => 'SUCCESS',
        ]), JSON_UNESCAPED_UNICODE);
        $u = $db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
        $u->execute([$merge, $row['id']]);
        echo "SUCCESS db-only id={$row['id']} (indexer notifies user)\n";

        continue;
    }

    if ($code === 'INCOMPLETE_DEPOSIT') {
        $asset = (string) ($row['currency'] ?? 'USDC');
        $minRecv = (string) ($meta['sendra_oneclick_min_receive_display'] ?? '');
        $minStellar = (string) ($meta['sendra_oneclick_min_stellar_display'] ?? '');
        $shouldNotify = $phone !== '' && empty($meta['oneclick_incomplete_user_notified']);
        $merge = json_encode(array_merge($meta, [
            'oneclick_swap_status' => 'INCOMPLETE_DEPOSIT',
            'oneclick_incomplete_user_notified' => $shouldNotify ? true : ($meta['oneclick_incomplete_user_notified'] ?? false),
        ]), JSON_UNESCAPED_UNICODE);
        $u = $db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
        $u->execute([$merge, $row['id']]);

        if ($shouldNotify) {
            $hint = '';
            if ($minStellar !== '' || $minRecv !== '') {
                $hint = "\n\nMinimum for bridge: *{$minStellar} USDC* on Stellar"
                    . ($minRecv !== '' ? " → *{$minRecv} {$asset}* on Aptos" : '')
                    . '.';
            }
            $wa->sendMessage(
                $phone,
                "⚠️ *Buy bridge update*\n\n" .
                "1Click received less Stellar USDC than this quote requires (*INCOMPLETE_DEPOSIT*)." .
                $hint . "\n\n" .
                'If Centiiv already paid, it may still settle — we keep checking. ' .
                'Otherwise funds return to Stellar after the quote deadline. ' .
                ($correlation !== '' ? "Ref: `{$correlation}`\n\n" : "\n") .
                'Contact support@usesendra.com if needed.'
            );
        }
        echo "INCOMPLETE_DEPOSIT id={$row['id']}\n";

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
