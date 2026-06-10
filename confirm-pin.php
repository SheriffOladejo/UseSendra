<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';
require __DIR__ . '/config/Database.php';

use App\Controllers\AuthController;
use App\Controllers\MessageController;
use App\Services\PinConfirmTokenService;
use App\Services\WhatsAppService;

$botConfigs = require __DIR__ . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834';
$accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
$botConfig = $botConfigs[$aptosPhoneId] ?? reset($botConfigs);

$db = Database::getInstance($botConfig['db_config'], useReplica: false)->getConnection();
$whatsappService = new WhatsAppService($accessToken, $aptosPhoneId);
$authController = new AuthController($db, $whatsappService);
$messageController = new MessageController($whatsappService, $authController, $db);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPage(string $title, string $bodyHtml, ?string $token = null): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#075E54">
    <title><?= h($title) ?> · Sendra</title>
    <style>
        :root {
            --brand: #075E54;
            --brand-dark: #054c44;
            --accent: #25D366;
            --bg: #f4f7f6;
            --card: #ffffff;
            --text: #1a1a1a;
            --muted: #667085;
            --danger: #d92d20;
            --border: #e4e7ec;
            --shadow: 0 12px 40px rgba(7, 94, 84, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(160deg, #e8f5f0 0%, var(--bg) 45%, #eef2ff 100%);
            color: var(--text);
            padding: 24px 16px 40px;
        }
        .wrap {
            max-width: 420px;
            margin: 0 auto;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: var(--brand);
            color: #fff;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 18px;
            box-shadow: var(--shadow);
        }
        .brand h1 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
        }
        .brand p {
            margin: 2px 0 0;
            font-size: 0.85rem;
            color: var(--muted);
        }
        .card {
            background: var(--card);
            border-radius: 18px;
            padding: 22px 20px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.8);
        }
        .card h2 {
            margin: 0 0 6px;
            font-size: 1.25rem;
        }
        .subtitle {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 0.92rem;
            line-height: 1.45;
        }
        .summary {
            background: #f8faf9;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 20px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px dashed #e8ecea;
            font-size: 0.92rem;
        }
        .summary-row:last-child { border-bottom: 0; padding-bottom: 0; }
        .summary-row span:first-child { color: var(--muted); }
        .summary-row strong { text-align: right; word-break: break-word; }
        .alert {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .alert-error {
            background: #fef3f2;
            color: #912018;
            border: 1px solid #fecdca;
        }
        .alert-success {
            background: #ecfdf3;
            color: #05603a;
            border: 1px solid #abefc6;
        }
        .alert-info {
            background: #eff8ff;
            color: #175cd3;
            border: 1px solid #b2ddff;
        }
        label {
            display: block;
            font-size: 0.88rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .pin-input {
            width: 100%;
            border: 2px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            font-size: 1.6rem;
            letter-spacing: 0.45em;
            text-align: center;
            font-weight: 600;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            -webkit-text-security: disc;
            text-security: disc;
        }
        .pin-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 4px rgba(7, 94, 84, 0.12);
        }
        .hint {
            margin-top: 8px;
            font-size: 0.8rem;
            color: var(--muted);
        }
        .btn {
            width: 100%;
            margin-top: 18px;
            border: 0;
            border-radius: 14px;
            padding: 15px 18px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color: #fff;
            box-shadow: 0 8px 24px rgba(7, 94, 84, 0.28);
        }
        .btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }
        .secure-note {
            margin-top: 16px;
            font-size: 0.8rem;
            color: var(--muted);
            text-align: center;
            line-height: 1.45;
        }
        .footer {
            margin-top: 22px;
            text-align: center;
            font-size: 0.78rem;
            color: var(--muted);
        }
        a { color: var(--brand); }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <div class="logo">S</div>
            <div>
                <h1>Sendra</h1>
                <p>Secure transaction confirmation</p>
            </div>
        </div>
        <div class="card">
            <?= $bodyHtml ?>
        </div>
        <p class="footer">Your PIN is verified securely and is not saved in WhatsApp chat.</p>
    </div>
    <?php if ($token !== null): ?>
    <script>
        (function () {
            var input = document.getElementById('pin');
            var form = document.getElementById('pin-form');
            var btn = document.getElementById('submit-btn');
            if (!input || !form) return;

            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
                if (btn) btn.disabled = this.value.length !== 6;
            });

            form.addEventListener('submit', function () {
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Confirming…';
                }
            });

            input.focus();
        })();
    </script>
    <?php endif; ?>
</body>
</html>
    <?php
    exit;
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$claims = PinConfirmTokenService::verify($token);

if ($claims === null) {
    renderPage(
        'Link expired',
        '<h2>Link expired or invalid</h2>'
        . '<p class="subtitle">This confirmation link is no longer valid. Return to WhatsApp and start the transaction again.</p>'
        . '<div class="alert alert-info">Links expire when the pending transaction times out (usually 5–30 minutes).</div>'
    );
}

$pendingId = $claims['pending_id'];
$phone = $claims['phone'];
$pending = $messageController->getPendingTransactionForWeb($pendingId);

if ($pending === null || ($pending['user_phone'] ?? '') !== $phone) {
    renderPage(
        'Transaction unavailable',
        '<h2>Transaction unavailable</h2>'
        . '<p class="subtitle">This transaction was already processed, cancelled, or expired. Check WhatsApp for an update.</p>'
    );
}

if (strtotime((string) $pending['expires_at']) < time()) {
    renderPage(
        'Transaction expired',
        '<h2>Transaction expired</h2>'
        . '<p class="subtitle">The confirmation window has closed. Start again from WhatsApp.</p>'
    );
}

$summary = $messageController->describePendingTransactionForWeb($pending);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = preg_replace('/\D/', '', (string) ($_POST['pin'] ?? ''));
    if (!preg_match('/^\d{6}$/', $pin)) {
        $error = 'Please enter a valid 6-digit PIN.';
    } else {
        $result = $messageController->confirmPendingTransactionWithPin($phone, $pin, $pendingId, false);
        if (!empty($result['success'])) {
            renderPage(
                'Confirmed',
                '<h2>Transaction confirmed</h2>'
                . '<p class="subtitle">Your transaction is processing. Return to WhatsApp for the receipt and explorer link.</p>'
                . '<div class="alert alert-success">You can close this page safely.</div>'
            );
        }

        $error = (string) ($result['error'] ?? 'Wrong PIN. Please try again.');
    }

    $summaryHtml = '';
    foreach ($summary['rows'] as $row) {
        $summaryHtml .= '<div class="summary-row"><span>' . h($row['label']) . '</span><strong>' . h($row['value']) . '</strong></div>';
    }

    renderPage(
        'Confirm transaction',
        '<h2>' . h($summary['title']) . '</h2>'
        . '<p class="subtitle">' . h($summary['subtitle']) . '</p>'
        . '<div class="alert alert-error">' . h($error) . '</div>'
        . '<div class="summary">' . $summaryHtml . '</div>'
        . '<form id="pin-form" method="post" action="confirm-pin.php" autocomplete="off">'
        . '<input type="hidden" name="token" value="' . h($token) . '">'
        . '<label for="pin">6-digit PIN</label>'
        . '<input class="pin-input" id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>'
        . '<p class="hint">Numbers only · not stored in WhatsApp chat</p>'
        . '<button class="btn" id="submit-btn" type="submit" disabled>Confirm transaction</button>'
        . '</form>',
        $token
    );
}

$summaryHtml = '';
foreach ($summary['rows'] as $row) {
    $summaryHtml .= '<div class="summary-row"><span>' . h($row['label']) . '</span><strong>' . h($row['value']) . '</strong></div>';
}

renderPage(
    'Confirm transaction',
    '<h2>' . h($summary['title']) . '</h2>'
    . '<p class="subtitle">' . h($summary['subtitle']) . '</p>'
    . '<div class="summary">' . $summaryHtml . '</div>'
    . '<form id="pin-form" method="post" action="confirm-pin.php" autocomplete="off">'
    . '<input type="hidden" name="token" value="' . h($token) . '">'
    . '<label for="pin">6-digit PIN</label>'
    . '<input class="pin-input" id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>'
    . '<p class="hint">Numbers only · not stored in WhatsApp chat</p>'
    . '<button class="btn" id="submit-btn" type="submit" disabled>Confirm transaction</button>'
    . '</form>'
    . '<p class="secure-note">🔒 Encrypted connection · PIN never appears in your WhatsApp thread</p>',
    $token
);
