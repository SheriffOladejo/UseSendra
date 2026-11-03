<?php

declare(strict_types=1);

/**
 * Optional security-question setup. Saves questions, then returns to the
 * transaction confirmation page that opened this link.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';
require __DIR__ . '/config/Database.php';

use App\Services\PinConfirmTokenService;
use AptosV2\Bootstrap\PinConfirmBootstrap;
use AptosV2\Support\SecurityQuestionForm;
use AptosV2\Support\SecurityQuestionService;

[$db, $whatsappService, $pinWeb, $postPinService] = PinConfirmBootstrap::boot();
$securityQuestions = new SecurityQuestionService($db);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function allowedReturn(string $return): string
{
    return in_array($return, ['confirm-pin.php', 'confirm-pin-v2.php'], true)
        ? $return
        : 'confirm-pin.php';
}

function returnToTransaction(string $return, string $token): void
{
    header('Location: /' . $return . '?token=' . rawurlencode($token), true, 303);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    exit;
}

function renderPage(string $title, string $bodyHtml): void
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
        .wrap { max-width: 420px; margin: 0 auto; }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .logo {
            width: 42px; height: 42px; border-radius: 12px; background: var(--brand); color: #fff;
            display: grid; place-items: center; font-weight: 700; font-size: 18px; box-shadow: var(--shadow);
        }
        .brand h1 { margin: 0; font-size: 1.15rem; }
        .brand p { margin: 2px 0 0; font-size: 0.85rem; color: var(--muted); }
        .card {
            background: var(--card); border-radius: 18px; padding: 22px 20px;
            box-shadow: var(--shadow); border: 1px solid rgba(255,255,255,0.8);
        }
        .card h2 { margin: 0 0 6px; font-size: 1.25rem; }
        .subtitle { margin: 0 0 18px; color: var(--muted); font-size: 0.92rem; line-height: 1.45; }
        .alert { border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; font-size: 0.9rem; line-height: 1.4; }
        .alert-error { background: #fef3f2; color: #912018; border: 1px solid #fecdca; }
        .alert-info { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }
        label { display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 8px; }
        .field { margin-bottom: 16px; }
        .text-input {
            width: 100%; border: 2px solid var(--border); border-radius: 14px; padding: 14px 16px;
            font-size: 1rem; outline: none;
        }
        .text-input:focus { border-color: var(--brand); box-shadow: 0 0 0 4px rgba(7, 94, 84, 0.12); }
        .secret-field { position: relative; }
        .secret-field .text-input { padding-right: 52px; }
        .secret-toggle {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            width: 36px; height: 36px; border: 0; border-radius: 10px; background: transparent;
            color: var(--muted); padding: 6px; cursor: pointer;
        }
        .secret-toggle svg { display: block; width: 22px; height: 22px; }
        .secret-toggle .icon-hide { display: none; }
        .secret-toggle.is-visible .icon-show { display: none; }
        .secret-toggle.is-visible .icon-hide { display: block; }
        .hint { margin: 0 0 16px; font-size: 0.8rem; color: var(--muted); line-height: 1.45; }
        .sq-row + .sq-row { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--border); }
        .sq-add-btn {
            margin: 0 0 8px; background: transparent; border: 0; padding: 0;
            color: var(--brand); font-weight: 700; cursor: pointer;
        }
        .btn {
            width: 100%; margin-top: 12px; border: 0; border-radius: 14px; padding: 15px 18px;
            font-size: 1rem; font-weight: 700; cursor: pointer;
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color: #fff; box-shadow: 0 8px 24px rgba(7, 94, 84, 0.28);
        }
        .btn:disabled { opacity: 0.65; cursor: not-allowed; }
        a.btn {
            display: block; text-align: center; text-decoration: none; box-sizing: border-box;
        }
        .btn-secondary {
            background: #fff; color: var(--brand); border: 2px solid var(--brand); box-shadow: none;
        }
        .footer { margin-top: 22px; text-align: center; font-size: 0.78rem; color: var(--muted); }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <div class="logo">S</div>
            <div>
                <h1>Sendra</h1>
                <p>Security questions</p>
            </div>
        </div>
        <div class="card"><?= $bodyHtml ?></div>
        <p class="footer">Answers are stored securely and are not shown again.</p>
    </div>
    <script>
        (function () {
            var form = document.getElementById('sq-form');
            var addAnother = document.getElementById('sq-add-another');
            var btn = document.getElementById('submit-btn');
            if (!form || !btn) return;

            function rows() {
                return form.querySelectorAll('[data-sq-row]');
            }

            function ready() {
                var complete = 0;
                var list = rows();
                for (var i = 0; i < list.length; i++) {
                    var fields = list[i].querySelectorAll('input');
                    var filled = 0;
                    for (var j = 0; j < fields.length; j++) {
                        if (fields[j].value.trim() !== '') filled++;
                    }
                    if (filled === 0) continue;
                    if (filled !== fields.length) return false;
                    if (fields[1].value.trim().toLowerCase() !== fields[2].value.trim().toLowerCase()) return false;
                    complete++;
                }
                return complete > 0;
            }

            function validate() {
                btn.disabled = !ready();
                if (addAnother) {
                    var remaining = parseInt(addAnother.getAttribute('data-remaining') || '1', 10);
                    addAnother.hidden = rows().length >= remaining;
                }
            }

            form.addEventListener('input', validate);
            form.addEventListener('click', function (event) {
                var toggle = event.target.closest('.secret-toggle');
                if (!toggle) return;
                var input = toggle.parentNode.querySelector('input');
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                toggle.classList.toggle('is-visible', show);
                toggle.setAttribute('aria-label', show ? 'Hide answer' : 'Show answer');
            });
            if (addAnother) {
                addAnother.addEventListener('click', function () {
                    var list = rows();
                    var remaining = parseInt(addAnother.getAttribute('data-remaining') || '1', 10);
                    if (list.length >= remaining) return;
                    var next = list[0].cloneNode(true);
                    var inputs = next.querySelectorAll('input');
                    for (var i = 0; i < inputs.length; i++) {
                        inputs[i].value = '';
                        inputs[i].type = inputs[i].name.indexOf('sq_question') === 0 ? 'text' : 'password';
                        inputs[i].name = inputs[i].name.replace(/\[\d+\]/, '[' + list.length + ']');
                    }
                    var toggles = next.querySelectorAll('.secret-toggle');
                    for (var t = 0; t < toggles.length; t++) {
                        toggles[t].classList.remove('is-visible');
                        toggles[t].setAttribute('aria-label', 'Show answer');
                    }
                    addAnother.parentNode.insertBefore(next, addAnother);
                    validate();
                });
            }
            form.addEventListener('submit', function () {
                btn.disabled = true;
                btn.textContent = 'Saving…';
            });
            validate();
        })();
    </script>
</body>
</html>
    <?php
    exit;
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$return = allowedReturn((string) ($_GET['return'] ?? $_POST['return'] ?? 'confirm-pin.php'));
$claims = PinConfirmTokenService::verify($token);

if ($claims === null || ($claims['mode'] ?? '') !== PinConfirmTokenService::MODE_CONFIRM) {
    renderPage(
        'Link expired',
        '<h2>Link expired or invalid</h2>'
        . '<p class="subtitle">Return to WhatsApp and open the confirmation link again.</p>'
        . '<div class="alert alert-info">Links expire after a short time for your security.</div>'
    );
}

$phone = (string) $claims['phone'];
$pendingId = (string) ($claims['pending_id'] ?? '');
$pending = $postPinService->getPendingForWeb($pendingId);
if ($pending === null || ($pending['user_phone'] ?? '') !== $phone || strtotime((string) $pending['expires_at']) < time()) {
    renderPage(
        'Transaction unavailable',
        '<h2>Transaction unavailable</h2>'
        . '<p class="subtitle">This transaction was already processed, cancelled, or expired. Start again from WhatsApp.</p>'
    );
}

$saved = $securityQuestions->count($phone);
$backUrl = '/' . $return . '?token=' . rawurlencode($token);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $collected = SecurityQuestionForm::collectNew($securityQuestions, $phone, $_POST);
    if (empty($collected['ok'])) {
        $error = (string) ($collected['error'] ?? 'Check the security questions and try again.');
    } else {
        $saveError = SecurityQuestionForm::savePending($securityQuestions, $phone, $collected['pending']);
        if ($saveError === null) {
            returnToTransaction($return, $token);
        }
        $error = $saveError;
    }
}

if ($saved >= SecurityQuestionService::MAX_QUESTIONS && $error === null) {
    renderPage(
        'Security questions',
        '<h2>Security questions</h2>'
        . '<p class="subtitle">You already have 5 security questions saved.</p>'
        . '<a class="btn" href="' . h($backUrl) . '">Back to transaction</a>'
    );
}

$body = '';
if ($error !== null && $error !== '') {
    $body .= '<div class="alert alert-error">' . h($error) . '</div>';
}
$body .= '<h2>Security questions</h2>'
    . '<p class="subtitle">Add a question only you can answer. When you save, you will return to confirm this transaction.</p>'
    . '<form id="sq-form" method="post" action="/security-questions.php" autocomplete="off">'
    . '<input type="hidden" name="token" value="' . h($token) . '">'
    . '<input type="hidden" name="return" value="' . h($return) . '">'
    . SecurityQuestionForm::renderSetupRows($saved, SecurityQuestionForm::postedRows())
    . '<button class="btn" id="submit-btn" type="submit" disabled>Save and continue</button>'
    . '</form>'
    . '<a class="btn btn-secondary" href="' . h($backUrl) . '">Back to transaction</a>';

renderPage('Security questions', $body);
