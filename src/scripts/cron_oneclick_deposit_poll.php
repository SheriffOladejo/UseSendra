<?php

/**
 * Poll 1Click for incoming Solana cross-chain deposits (USDT/USDC -> Aptos).
 *
 * Crontab example (every minute):
 *   * * * * * php /var/www/html/coinectra/src/scripts/cron_oneclick_deposit_poll.php >> /var/log/oneclick_deposit_poll.log 2>&1
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

$bots = require dirname(__DIR__, 2) . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834';
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

$stmt = $db->query("
    SELECT pt.id, pt.user_id, pt.amount, pt.currency, pt.human_description, pt.created_at, u.phone_number
    FROM pending_transactions pt
    LEFT JOIN users u ON u.Id = pt.user_id
    WHERE pt.tx_type IN ('deposit_1click_solana', 'deposit_1click_crosschain')
      AND pt.status = 'pending'
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No pending cross-chain deposit transactions found.\n";
    exit(0);
}

foreach ($rows as $row) {
    $meta = json_decode((string) ($row['human_description'] ?? ''), true);
    if (!is_array($meta)) {
        continue;
    }

    $depositAddress = (string) ($meta['deposit_address'] ?? '');
    if ($depositAddress === '') {
        continue;
    }

    $phoneId = (string) ($meta['origin_whatsapp_phone_id'] ?? $aptosPhoneId);
    $phone = (string) ($row['phone_number'] ?? '');
    $asset = (string) ($row['currency'] ?? 'USDC');
    $userWa = \App\Services\WhatsAppBotResolver::serviceForPhoneId($phoneId, $access);

    $st = $oneClick->getStatus($depositAddress);
    if (!($st['success'] ?? false) || !is_array($st['parsed'] ?? null)) {
        continue;
    }

    $parsed = $st['parsed'];
    $status = strtoupper((string) ($parsed['status'] ?? ''));

    if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'], true)) {
        $swapDetails = $parsed['swapDetails'] ?? [];
        $receivedAmount = $swapDetails['amountOutFormatted']
            ?? $parsed['quoteResponse']['quote']['amountOutFormatted']
            ?? (string) $row['amount'];

        $network = (string) ($meta['network'] ?? 'Solana');

        // Mark completed
        $up = $db->prepare("UPDATE pending_transactions SET status = 'completed' WHERE id = ?");
        $up->execute([$row['id']]);

        // Insert into transaction_history
        try {
            $txh = $db->prepare("
                INSERT INTO transaction_history 
                (user_id, type, amount, currency, status, human_description, created_at)
                VALUES (?, 'deposit_crosschain', ?, ?, 'completed', ?, NOW())
            ");
            $txh->execute([
                $row['user_id'],
                $receivedAmount,
                $asset,
                "Deposit {$asset} from {$network} via 1Click (Address: {$depositAddress})"
            ]);
        } catch (\Throwable $e) {
            error_log("Failed to insert transaction_history for deposit row {$row['id']}: " . $e->getMessage());
        }

        if ($phone !== '' && $userWa !== null) {
            $userWa->sendMessage(
                $phone,
                "✅ *Deposit Confirmed!*\n\n" .
                "Your deposit from *{$network}* has been processed and credited to your Sendra Wallet.\n\n" .
                "• *Amount Credited:* ~{$receivedAmount} {$asset} (Aptos)\n" .
                "• *Source Network:* {$network}\n" .
                "• *Deposit Address:* `{$depositAddress}`\n\n" .
                "Type *balance* to check your updated wallet balance."
            );
        }
        echo "Deposit transaction #{$row['id']} completed successfully.\n";
    } elseif ($status === 'EXPIRED' || $status === 'FAILED' || $status === 'REFUNDED') {
        $up = $db->prepare("UPDATE pending_transactions SET status = 'failed' WHERE id = ?");
        $up->execute([$row['id']]);

        if ($phone !== '' && $userWa !== null) {
            $userWa->sendMessage(
                $phone,
                "ℹ️ *Deposit Status Update*\n\n" .
                "Your Solana deposit request (Address: `{$depositAddress}`) has expired or was refunded.\n\n" .
                "Type *deposit* anytime to request a fresh deposit address."
            );
        }
        echo "Deposit transaction #{$row['id']} marked failed ({$status}).\n";
    }
}
