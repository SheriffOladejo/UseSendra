<?php

/**
 * Browser return URLs after Centiiv checkout (success / failure).
 * Point CENTIIV_PAYMENT_SUCCESS_URL / CENTIIV_PAYMENT_FAILURE_URL here, e.g.
 *   https://usesendra.com/CentiivPaymentReturn.php?outcome=success
 *   https://usesendra.com/CentiivPaymentReturn.php?outcome=failure
 *
 * @see https://c-vmch.readme.io/reference/callback-urls
 */

declare(strict_types=1);

$outcome = strtolower((string) ($_GET['outcome'] ?? $_GET['status'] ?? 'unknown'));
$ok = in_array($outcome, ['success', 'ok', 'paid', 'completed'], true);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sendra — Payment <?php echo $ok ? 'received' : 'update'; ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 28rem; margin: 3rem auto; padding: 0 1rem; line-height: 1.5; color: #111; }
        h1 { font-size: 1.25rem; }
        p { color: #444; }
    </style>
</head>
<body>
<?php if ($ok): ?>
    <h1>Payment received</h1>
    <p>You can close this page and return to WhatsApp. Your Sendra logistics chat will update shortly.</p>
<?php else: ?>
    <h1>Payment not completed</h1>
    <p>If you still want the job, open the payment link from WhatsApp again or say <strong>hi</strong> in the bot.</p>
<?php endif; ?>
</body>
</html>
