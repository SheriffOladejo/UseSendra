<?php

declare(strict_types=1);

/**
 * Background worker: execute off-ramp after web PIN confirm.
 *
 * Usage: php src/scripts/run_offramp_after_pin.php <pending_id> <phone>
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';
require dirname(__DIR__, 2) . '/config/Database.php';

use AptosV2\Bootstrap\PinConfirmBootstrap;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Support\WhatsAppBotResolver;

$pendingId = trim((string) ($argv[1] ?? ''));
$phone = trim((string) ($argv[2] ?? ''));

if ($pendingId === '' || $phone === '') {
    fwrite(STDERR, "Usage: php src/scripts/run_offramp_after_pin.php <pending_id> <phone>\n");
    exit(2);
}

echo gmdate('c') . " offramp worker start pending={$pendingId} phone={$phone}\n";

try {
    $db = PinConfirmBootstrap::connectAptosDb();
    $pendingRepo = new PendingTransactionRepository($db);
    $pending = $pendingRepo->findById($pendingId);
    $originId = WhatsAppBotResolver::originPhoneNumberIdFromPending($pending);
    [, , , $postPinService] = PinConfirmBootstrap::boot($originId);
    $result = $postPinService->runClaimedOfframp($phone, $pendingId);

    echo gmdate('c') . ' offramp worker done success=' . (!empty($result['success']) ? '1' : '0') . "\n";
    if (empty($result['success']) && !empty($result['error'])) {
        echo 'error=' . $result['error'] . "\n";
    }
} catch (\Throwable $e) {
    echo gmdate('c') . ' offramp worker exception: ' . $e->getMessage() . "\n";
    exit(1);
}
