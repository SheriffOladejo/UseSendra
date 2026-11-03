<?php

/**
 * 1Click bridge status for Sendra buys (Stellar → Aptos).
 *
 * On SUCCESS: completes buy via {@see SendraIndexerDepositNotifyService::completeBuyOnramp}
 * (receipt + “crypto received” message) when the Aptos indexer has not already done so.
 * Also backfills missing receipts for recent SUCCESS rows.
 * Notifies on INCOMPLETE_DEPOSIT / FAILED / REFUNDED.
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
    if (\App\Services\SendraIndexerDepositNotifyService::skipsOneClickOnrampMeta($meta)) {
        continue;
    }
    if (!empty($meta['indexer_deposit_notified']) && !empty($meta['buy_receipt_sent'])) {
        continue;
    }

    $deposit = (string) ($meta['sendra_oneclick_deposit_address'] ?? $meta['sendra_oneclick_deposit_stellar'] ?? $row['recipient_address'] ?? '');
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
        $swap = is_array($st['data']['swapDetails'] ?? null) ? $st['data']['swapDetails'] : [];
        $recv = 0.0;
        foreach (['amountOutFormatted', 'amount_out_formatted', 'amountOut'] as $k) {
            if (isset($swap[$k]) && is_numeric($swap[$k])) {
                $recv = (float) $swap[$k];
                if ($recv > 1_000_000) {
                    $recv = (float) \App\Services\SendraOneClickService::smallestUnitsToFloatString((string) (int) $recv, 6);
                }
                break;
            }
        }
        if ($recv <= 0) {
            $recv = (float) ($row['amount'] ?? 0);
        }
        $asset = strtoupper((string) ($row['currency'] ?? 'USDC'));
        $outcome = \App\Services\SendraIndexerDepositNotifyService::completeBuyOnramp(
            $db,
            $wa,
            $row,
            $recv,
            $asset,
            null,
            is_array($st['data'] ?? null) ? $st['data'] : null,
            'oneclick_poll'
        );
        echo 'SUCCESS id=' . $row['id']
            . ' receipt=' . ($outcome['receipt_sent'] ? '1' : '0')
            . ' msg=' . ($outcome['message_sent'] ? '1' : '0')
            . ($outcome['skipped'] ? ' skipped=1' : '') . "\n";

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
                "The bridge received less Stellar USDC than this quote requires." .
                $hint . "\n\n" .
                'If Naira already left your bank, it may still settle — we keep checking. ' .
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
                "Conversion to Aptos reported: *{$code}*.\n" .
                ($correlation !== '' ? "Ref: `{$correlation}`\n\n" : '') .
                'Contact support@usesendra.com if Naira was already debited.'
            );
        }
        echo "terminal {$code} id={$row['id']}\n";
    }
}

$backfilled = \App\Services\SendraIndexerDepositNotifyService::backfillMissingBuyReceipts($db, $wa, 7);
if ($backfilled > 0) {
    echo "Backfilled buy receipts: {$backfilled}\n";
}

echo 'Done. Rows scanned: ' . count($rows) . "\n";
