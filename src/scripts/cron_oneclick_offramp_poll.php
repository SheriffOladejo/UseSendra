<?php

/**
 * Poll 1Click for Sendra sells where Aptos funding tx is confirmed but swap not yet SUCCESS.
 *
 * Crontab example (every minute):
 *   * * * * * php /var/www/html/coinectra/src/scripts/cron_oneclick_offramp_poll.php >> /var/log/oneclick_poll.log 2>&1
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
// After bootstrap, error_log() goes to SENDRA_PHP_ERROR_LOG (e.g. same file as webhook php-debug.log)
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

// Do not gate on expires_at: PHP stores UTC via date() while MySQL NOW() may be local,
// which caused confirmed off-ramps to never be polled after ~30 minutes.
$stmt = $db->query("
    SELECT id, user_phone, recipient_address, human_description, expires_at
    FROM pending_transactions
    WHERE tx_type IN ('offramp_oneclick_centiiv', 'offramp_panora_oneclick_centiiv')
      AND status = 'confirmed'
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
    if (!empty($meta['oneclick_bridge_user_notified'])) {
        continue;
    }

    $deposit = (string) ($row['recipient_address'] ?? '');
    if ($deposit === '') {
        continue;
    }
    $memo = isset($meta['oneclick_deposit_memo']) && is_string($meta['oneclick_deposit_memo']) && $meta['oneclick_deposit_memo'] !== ''
        ? $meta['oneclick_deposit_memo'] : null;

    $st = $oneClick->getStatus($deposit, $memo);
    if (!$st['success']) {
        $logE = (string) ($_ENV['SENDRA_1CLICK_TRACE'] ?? getenv('SENDRA_1CLICK_TRACE') ?: '1');
        if ($logE !== '0' && $logE !== 'off') {
            error_log('[Sendra 1Click offramp cron] ' . json_encode([
                'step' => 'getStatus_fail',
                'id' => $row['id'],
                'user' => $row['user_phone'] ?? null,
                'oneclick_deposit' => $deposit,
                'http' => (int) ($st['http_code'] ?? 0),
                'error' => $st['error'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        continue;
    }

    $code = \App\Services\SendraOneClickService::parseStatus($st['data']);
    $phone = (string) ($row['user_phone'] ?? '');
    $correlation = (string) ($meta['oneclick_correlation_id'] ?? '');

    if (strtolower((string) ($_ENV['SENDRA_1CLICK_TRACE'] ?? getenv('SENDRA_1CLICK_TRACE') ?: '1')) !== '0') {
        error_log('[Sendra 1Click offramp cron] ' . json_encode([
            'step' => 'poll',
            'id' => $row['id'],
            'user' => $phone,
            'oneclick_status' => $code,
            'correlation' => $correlation !== '' ? $correlation : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if ($code === 'SUCCESS') {
        $merge = json_encode(array_merge($meta, [
            'need_oneclick_poll' => false,
            'oneclick_swap_status' => 'SUCCESS',
            'oneclick_bridge_user_notified' => true,
        ]), JSON_UNESCAPED_UNICODE);
        $u = $db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
        $u->execute([$merge, $row['id']]);

        if ($phone !== '') {
            $wa->sendMessage(
                $phone,
                "✅ *Swap confirmed*\n\n" .
                "Your **1Click** transfer to Stellar USDC has completed. " .
                "Centiiv should credit your order and continue with **NGN** to your bank.\n\n" .
                ($correlation !== '' ? "Ref: `{$correlation}`\n\n" : '') .
                'You’ll get another message when Centiiv marks the order completed.'
            );
        }
        echo "SUCCESS notified id={$row['id']}\n";

        continue;
    }

    if (in_array($code, ['FAILED', 'REFUNDED'], true)) {
        $merge = json_encode(array_merge($meta, [
            'need_oneclick_poll' => false,
            'oneclick_swap_status' => $code,
            'oneclick_bridge_user_notified' => true,
        ]), JSON_UNESCAPED_UNICODE);
        $u = $db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
        $u->execute([$merge, $row['id']]);

        if ($phone !== '') {
            $wa->sendMessage(
                $phone,
                "⚠️ *Swap update*\n\n" .
                "1Click reported: *{$code}*.\n" .
                ($correlation !== '' ? "Ref: `{$correlation}`\n\n" : '') .
                'Contact support@usesendra.com if you need help.'
            );
        }
        echo "terminal {$code} id={$row['id']}\n";
    }
}

echo "Done. Rows scanned: " . count($rows) . "\n";
