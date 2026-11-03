<?php

/**
 * Poll 1Click for Sendra sells where Aptos funding tx is confirmed but swap not yet SUCCESS.
 * Also recovers missed Centiiv intermediary bank disbursements for Arc/Stellar/1Click sells
 * without hammering GET /requests (rate-limit budget + backoff).
 *
 * Crontab example (every minute):
 *   * * * * * php /var/www/sendra/wallet/src/scripts/cron_oneclick_offramp_poll.php >> /var/log/oneclick_poll.log 2>&1
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
$centiiv = new \App\Services\SendraCentiivRampService();

/** @var int Max Centiiv GET /requests calls per cron run (stops 429 storms). */
$GLOBALS['sendra_centiiv_get_budget'] = (int) ($_ENV['SENDRA_CENTIIV_POLL_MAX_GETS'] ?? getenv('SENDRA_CENTIIV_POLL_MAX_GETS') ?: 8);
if ($GLOBALS['sendra_centiiv_get_budget'] < 1) {
    $GLOBALS['sendra_centiiv_get_budget'] = 8;
}
$GLOBALS['sendra_centiiv_gets_used'] = 0;

// Do not gate on expires_at: PHP stores UTC via date() while MySQL NOW() may be local,
// which caused confirmed off-ramps to never be polled after ~30 minutes.
$stmt = $db->query("
    SELECT id, user_phone, recipient_address, human_description, expires_at, tx_type, amount, currency, created_at
    FROM pending_transactions
    WHERE tx_type IN ('offramp_oneclick_centiiv', 'offramp_panora_oneclick_centiiv', 'offramp_stellar_centiiv', 'offramp_arc_centiiv')
      AND status = 'confirmed'
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $meta = json_decode((string) ($row['human_description'] ?? ''), true);
    if (!is_array($meta)) {
        continue;
    }
    $txType = (string) ($row['tx_type'] ?? '');
    if (empty($meta['need_oneclick_poll'])) {
        $tryReceipt = $txType === 'offramp_stellar_centiiv'
            || $txType === 'offramp_arc_centiiv'
            || ($meta['oneclick_swap_status'] ?? '') === 'SUCCESS';
        if ($tryReceipt) {
            sendra_try_recover_centiiv_offramp($centiiv, $wa, $db, $row, $meta);
        }
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
        $http = (int) ($st['http_code'] ?? 0);
        if ($http === 404) {
            $failCount = (int) ($meta['oneclick_404_count'] ?? 0) + 1;
            if ($failCount >= 3) {
                $merge = json_encode(array_merge($meta, [
                    'need_oneclick_poll' => false,
                    'oneclick_swap_status' => 'NOT_FOUND',
                    'oneclick_404_count' => $failCount,
                ]), JSON_UNESCAPED_UNICODE);
                $u = $db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
                $u->execute([$merge, $row['id']]);
                echo "404 stale cleared id={$row['id']}\n";
            } else {
                $merge = json_encode(array_merge($meta, ['oneclick_404_count' => $failCount]), JSON_UNESCAPED_UNICODE);
                $u = $db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
                $u->execute([$merge, $row['id']]);
            }

            continue;
        }

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

        $metaAfter = array_merge($meta, [
            'need_oneclick_poll' => false,
            'oneclick_swap_status' => 'SUCCESS',
            'oneclick_bridge_user_notified' => true,
        ]);
        sendra_try_recover_centiiv_offramp($centiiv, $wa, $db, $row, $metaAfter);

        echo "SUCCESS id={$row['id']}\n";

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

        echo "terminal {$code} id={$row['id']}\n";
    }
}

echo 'Done. Rows scanned: ' . count($rows)
    . ' centiiv_gets=' . (int) ($GLOBALS['sendra_centiiv_gets_used'] ?? 0)
    . '/' . (int) ($GLOBALS['sendra_centiiv_get_budget'] ?? 0)
    . "\n";

/**
 * Recover missed Centiiv FULFILLED handling:
 * - Intermediary exact-₦: disburse from pool + receipt
 * - Direct bank beneficiary: receipt image only
 *
 * Rate-limits GET /requests and stops polling settled / manual / terminal rows.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $meta
 */
function sendra_try_recover_centiiv_offramp(
    \App\Services\SendraCentiivRampService $centiiv,
    \App\Services\WhatsAppService $wa,
    \PDO $db,
    array $row,
    array $meta
): void {
    $orderId = trim((string) ($meta['centiiv_order_id'] ?? $meta['order_id'] ?? ''));
    $phone = trim((string) ($row['user_phone'] ?? ''));
    if ($orderId === '' || $phone === '' || !$centiiv->isConfigured()) {
        return;
    }

    // Permanently handled — never GET again.
    if (sendra_centiiv_meta_is_poll_done($meta)) {
        return;
    }

    $existingDisburse = \App\Services\SendraCentiivRampService::loadIntermediaryDisburseContextForOrder($orderId);
    if (is_array($existingDisburse) && !empty($existingDisburse['disburse_tracking_id'])) {
        sendra_centiiv_mark_meta($db, $row['id'], $meta, [
            'intermediary_disburse_complete' => true,
            'centiiv_offramp_poll_done' => gmdate('c'),
            'centiiv_offramp_poll_done_reason' => 'disburse_cache',
        ]);

        return;
    }

    // Webhook keeps the terminal lock only when notify/disburse completed successfully.
    // Mark poll-done without another GET /requests (major 429 fix).
    if (sendra_centiiv_terminal_lock_exists($orderId)) {
        $lockPatch = [
            'centiiv_offramp_poll_done' => gmdate('c'),
            'centiiv_offramp_poll_done_reason' => 'terminal_lock',
        ];
        if (sendra_centiiv_meta_looks_intermediary($meta)) {
            $lockPatch['intermediary_disburse_complete'] = true;
        }
        sendra_centiiv_mark_meta($db, $row['id'], $meta, $lockPatch);

        return;
    }

    // Non-intermediary receipt already recovered.
    if (!empty($meta['centiiv_receipt_sent_via_cron']) && !sendra_centiiv_meta_looks_intermediary($meta)) {
        sendra_centiiv_mark_meta($db, $row['id'], $meta, [
            'centiiv_offramp_poll_done' => gmdate('c'),
            'centiiv_offramp_poll_done_reason' => 'receipt_sent',
        ]);

        return;
    }

    // Backoff after 429 / transient failures.
    $pollAfter = trim((string) ($meta['centiiv_poll_after'] ?? ''));
    if ($pollAfter !== '') {
        $afterTs = strtotime($pollAfter);
        if ($afterTs !== false && $afterTs > time()) {
            return;
        }
    }

    // Stop forever-polling ancient confirmed rows (webhook should have finished them).
    $maxAgeDays = (int) ($_ENV['SENDRA_CENTIIV_POLL_MAX_AGE_DAYS'] ?? getenv('SENDRA_CENTIIV_POLL_MAX_AGE_DAYS') ?: 14);
    if ($maxAgeDays < 1) {
        $maxAgeDays = 14;
    }
    $createdAt = trim((string) ($row['created_at'] ?? ''));
    if ($createdAt !== '') {
        $createdTs = strtotime($createdAt . ' UTC');
        if ($createdTs === false) {
            $createdTs = strtotime($createdAt);
        }
        if ($createdTs !== false && (time() - $createdTs) > ($maxAgeDays * 86400)) {
            sendra_centiiv_mark_meta($db, $row['id'], $meta, [
                'centiiv_offramp_poll_done' => gmdate('c'),
                'centiiv_offramp_poll_done_reason' => 'max_age',
            ]);

            return;
        }
    }

    // Budget: avoid 429 storms across hundreds of confirmed rows.
    $budget = (int) ($GLOBALS['sendra_centiiv_get_budget'] ?? 8);
    $used = (int) ($GLOBALS['sendra_centiiv_gets_used'] ?? 0);
    if ($used >= $budget) {
        return;
    }

    $GLOBALS['sendra_centiiv_gets_used'] = $used + 1;
    $res = $centiiv->getRequest($orderId);
    if (empty($res['success'])) {
        $http = (int) ($res['http_code'] ?? 0);
        $failCount = (int) ($meta['centiiv_poll_fail_count'] ?? 0) + 1;
        $backoffSec = $http === 429
            ? min(3600, 300 * (2 ** min(4, $failCount - 1)))
            : min(1800, 120 * $failCount);
        sendra_centiiv_mark_meta($db, $row['id'], $meta, [
            'centiiv_poll_fail_count' => $failCount,
            'centiiv_poll_after' => gmdate('c', time() + $backoffSec),
            'centiiv_poll_last_http' => $http,
        ]);
        if ($http === 429) {
            // Global cool-down: burn remaining budget this minute.
            $GLOBALS['sendra_centiiv_gets_used'] = $budget;
            echo "centiiv 429 backoff order={$orderId} sec={$backoffSec}\n";
        }

        return;
    }

    $data = is_array($res['data']['data'] ?? null) ? $res['data']['data'] : ($res['data'] ?? []);
    if (!is_array($data)) {
        return;
    }
    $status = strtolower(trim((string) ($data['status'] ?? '')));
    if ($status === '') {
        return;
    }

    if (sendra_centiiv_status_is_terminal_failure($status)) {
        sendra_centiiv_mark_meta($db, $row['id'], $meta, [
            'centiiv_offramp_poll_done' => gmdate('c'),
            'centiiv_offramp_poll_done_reason' => 'terminal_' . $status,
            'centiiv_last_status' => (string) ($data['status'] ?? $status),
        ]);
        echo "centiiv terminal {$status} order={$orderId} id={$row['id']}\n";

        return;
    }

    if (!sendra_centiiv_status_is_success($status)) {
        // Still pending/processing — soft backoff so we don't re-GET every minute.
        sendra_centiiv_mark_meta($db, $row['id'], $meta, [
            'centiiv_poll_fail_count' => 0,
            'centiiv_poll_after' => gmdate('c', time() + 180),
            'centiiv_last_status' => (string) ($data['status'] ?? $status),
        ]);

        return;
    }

    $isIntermediary = sendra_centiiv_order_is_intermediary($data, $meta);
    if ($isIntermediary) {
        if (!sendra_centiiv_claim_terminal_lock($orderId)) {
            return;
        }
        $ok = sendra_centiiv_recover_intermediary_disburse($centiiv, $wa, $db, $row, $meta, $data, $orderId, $phone);
        if (!$ok) {
            // Pool shortfall will not self-heal via re-GET; stop polling and keep lock after one notify.
            if (\App\Services\SendraCentiivRampService::poolShortfallUserNotifyClaimed($orderId)) {
                sendra_centiiv_mark_meta($db, $row['id'], $meta, [
                    'centiiv_offramp_poll_done' => gmdate('c'),
                    'centiiv_offramp_poll_done_reason' => 'pool_shortfall',
                    'pool_shortfall_notified' => gmdate('c'),
                    'centiiv_last_status' => (string) ($data['status'] ?? 'FULFILLED'),
                ]);
                echo "pool shortfall stop-poll order={$orderId} id={$row['id']}\n";

                return;
            }
            sendra_centiiv_release_terminal_lock($orderId);
            sendra_centiiv_mark_meta($db, $row['id'], $meta, [
                'centiiv_poll_after' => gmdate('c', time() + 600),
                'centiiv_last_status' => (string) ($data['status'] ?? 'FULFILLED'),
            ]);

            return;
        }
        sendra_centiiv_mark_meta($db, $row['id'], $meta, [
            'intermediary_disburse_complete' => true,
            'centiiv_receipt_sent_via_cron' => gmdate('c'),
            'centiiv_offramp_poll_done' => gmdate('c'),
            'centiiv_offramp_poll_done_reason' => 'intermediary_disburse',
            'centiiv_poll_fail_count' => 0,
        ]);
        echo "intermediary disburse recovered order={$orderId} id={$row['id']}\n";

        return;
    }

    // Direct-to-bank fulfilment: receipt only (legacy path).
    if (!empty($meta['centiiv_receipt_sent_via_cron'])) {
        return;
    }
    if (!sendra_centiiv_claim_terminal_lock($orderId)) {
        return;
    }

    $event = ['data' => $data, 'timestamp' => gmdate('c')];
    $ledger = \App\Services\SendraCentiivPayoutReceiptFactory::resolveUserSellLedger(
        $meta,
        isset($row['amount']) ? (string) $row['amount'] : null,
        isset($row['currency']) ? (string) $row['currency'] : null
    );
    $norm = [
        'user_phone' => $phone,
        'status' => (string) ($data['status'] ?? 'FULFILLED'),
        'order_label' => $orderId,
        'oneclick_correlation_id' => (string) ($meta['oneclick_correlation_id'] ?? $meta['sendra_oneclick_correlation_id'] ?? ''),
    ];
    if ($ledger['amount'] !== null) {
        $norm['ledger_asset'] = $ledger['asset'];
        $norm['ledger_amount'] = (string) $ledger['amount'];
    }

    $path = null;
    try {
        $model = \App\Services\SendraCentiivPayoutReceiptFactory::build(
            $event,
            $norm,
            trim((string) ($meta['sendra_payout_reference'] ?? '')) ?: null
        );
        $path = \App\Services\SendraPayoutReceiptImageService::renderToTempFile($model);
        if ($path === null || !is_readable($path)) {
            sendra_centiiv_release_terminal_lock($orderId);

            return;
        }
        $img = $wa->sendImage($phone, $path, '✅ Sendra — bank payout receipt');
        if ($img === false || (is_array($img) && empty($img['success']))) {
            sendra_centiiv_release_terminal_lock($orderId);

            return;
        }
        sendra_centiiv_mark_meta($db, $row['id'], $meta, [
            'centiiv_receipt_sent_via_cron' => gmdate('c'),
            'centiiv_offramp_poll_done' => gmdate('c'),
            'centiiv_offramp_poll_done_reason' => 'receipt_sent',
            'centiiv_poll_fail_count' => 0,
        ]);
        echo "receipt recovered order={$orderId} id={$row['id']}\n";
    } catch (\Throwable $e) {
        sendra_centiiv_release_terminal_lock($orderId);
        error_log('[Sendra 1Click offramp cron] receipt recovery failed: ' . $e->getMessage());
    } finally {
        if ($path !== null) {
            @unlink($path);
        }
    }
}

/**
 * @param array<string, mixed> $meta
 */
function sendra_centiiv_meta_is_poll_done(array $meta): bool
{
    if (!empty($meta['centiiv_offramp_poll_done'])) {
        return true;
    }
    if (!empty($meta['manual_settled_at']) || !empty($meta['manual_user_paid_ngn'])) {
        return true;
    }
    if (!empty($meta['intermediary_disburse_complete'])) {
        return true;
    }
    if (!empty($meta['intermediary_disburse_skipped_manual'])) {
        return true;
    }

    return false;
}

/**
 * Exact-₦ sells store target_receive_ngn > 0 and use the intermediary pool when enabled.
 *
 * @param array<string, mixed> $meta
 */
function sendra_centiiv_meta_looks_intermediary(array $meta): bool
{
    if (($meta['sendra_intermediary_payout'] ?? '') === '1') {
        return true;
    }
    $sellCtx = is_array($meta['sell_ctx'] ?? null) ? $meta['sell_ctx'] : [];
    $requested = \App\Services\SendraCentiivRampService::resolveIntermediaryRequestedNgnFromPendingMeta($meta, $sellCtx);

    return $requested > 0 && \App\Services\SendraCentiivRampService::isIntermediaryNgnPayoutEnabled();
}

/**
 * @param array<string, mixed> $data Centiiv order body
 * @param array<string, mixed> $meta
 */
function sendra_centiiv_order_is_intermediary(array $data, array $meta): bool
{
    $ben = $data['beneficiary'] ?? null;
    if (is_array($ben)) {
        $ext = trim((string) ($ben['externalId'] ?? $ben['external_id'] ?? ''));
        if ($ext === 'sendra_intermediary_ngn') {
            return true;
        }
    }

    return sendra_centiiv_meta_looks_intermediary($meta);
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $orderData
 */
function sendra_centiiv_recover_intermediary_disburse(
    \App\Services\SendraCentiivRampService $centiiv,
    \App\Services\WhatsAppService $wa,
    \PDO $db,
    array $row,
    array $meta,
    array $orderData,
    string $orderId,
    string $phone
): bool {
    $sellCtx = is_array($meta['sell_ctx'] ?? null) ? $meta['sell_ctx'] : [];
    $requested = \App\Services\SendraCentiivRampService::resolveIntermediaryRequestedNgnFromPendingMeta($meta, $sellCtx);
    $userBank = \App\Services\SendraCentiivRampService::resolveIntermediaryUserBankFromPendingMeta($meta, $sellCtx);

    $receivedNgn = null;
    foreach ($orderData['fulfillments'] ?? [] as $f) {
        if (!is_array($f)) {
            continue;
        }
        $amt = $f['amount'] ?? null;
        // Ignore tiny placeholder amounts Centiiv sometimes puts before real NGN fulfilment.
        if ($amt !== null && is_numeric($amt) && (float) $amt >= 100) {
            $receivedNgn = (float) $amt;
            break;
        }
    }
    if ($receivedNgn === null) {
        foreach ($orderData['fulfillments'] ?? [] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $amt = $f['amount'] ?? null;
            if ($amt !== null && is_numeric($amt) && (float) $amt > 0) {
                $receivedNgn = (float) $amt;
                break;
            }
        }
    }

    $ctx = [
        'user_phone' => $phone,
        'order_label' => $orderId,
        'centiiv_order_id' => $orderId,
        'requested_ngn' => $requested,
        'received_ngn' => $receivedNgn,
        'user_account_number' => $userBank['account_number'],
        'user_bank_code' => $userBank['bank_code'],
        'user_account_name' => $userBank['account_name'],
        'user_bank_name' => $userBank['bank_name'],
        'sendra_ledger_asset' => (string) ($meta['sendra_ledger_asset'] ?? $row['currency'] ?? 'USDC'),
        'sendra_ledger_amount' => (string) ($meta['sendra_ledger_amount'] ?? $row['amount'] ?? ''),
        'quoted_receive_ngn' => isset($meta['sendra_quoted_receive_ngn']) ? (float) $meta['sendra_quoted_receive_ngn'] : null,
        'centiiv_order_rate' => \App\Services\SendraCentiivRampService::extractOfficialNgnRateFromOrder($orderData),
        'sender_name' => \App\Services\SendraCentiivPayoutReceiptFactory::resolveReceiptSenderLabel(true, $orderData),
    ];

    return \App\Services\SendraIntermediaryDisburse::payUserFromPool($db, $wa, $ctx);
}

/**
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $patch
 */
function sendra_centiiv_mark_meta(\PDO $db, string $id, array &$meta, array $patch): void
{
    $meta = array_merge($meta, $patch);
    $u = $db->prepare('UPDATE pending_transactions SET human_description = ? WHERE id = ?');
    $u->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
}

function sendra_centiiv_status_is_success(string $status): bool
{
    foreach (['fulfilled', 'complete', 'completed', 'success', 'paid', 'settled'] as $needle) {
        if (str_contains($status, $needle)) {
            return true;
        }
    }

    return false;
}

function sendra_centiiv_status_is_terminal_failure(string $status): bool
{
    foreach (['expired', 'failed', 'refunded', 'cancelled', 'canceled', 'rejected'] as $needle) {
        if (str_contains($status, $needle)) {
            return true;
        }
    }

    return false;
}

function sendra_centiiv_terminal_lock_path(string $orderId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $orderId . '_sell') ?: 'order_sell';

    return sys_get_temp_dir() . '/sendra_centiiv_terminal_' . $safe;
}

function sendra_centiiv_terminal_lock_exists(string $orderId): bool
{
    return is_file(sendra_centiiv_terminal_lock_path($orderId));
}

function sendra_centiiv_claim_terminal_lock(string $orderId): bool
{
    $h = @fopen(sendra_centiiv_terminal_lock_path($orderId), 'xb');
    if ($h === false) {
        return false;
    }
    fwrite($h, (string) time());
    fclose($h);

    return true;
}

function sendra_centiiv_release_terminal_lock(string $orderId): void
{
    @unlink(sendra_centiiv_terminal_lock_path($orderId));
}
