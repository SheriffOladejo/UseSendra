<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';
require __DIR__ . '/config/Database.php';

use App\Models\User;
use App\Services\PinConfirmTokenService;
use AptosV2\Bootstrap\PinConfirmBootstrap;
use AptosV2\Support\SecurityQuestionForm;
use AptosV2\Support\SecurityQuestionService;

[$db, $whatsappService, $pinWeb, $postPinService] = PinConfirmBootstrap::boot();

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPage(string $title, string $bodyHtml, ?string $token = null, ?string $redirectUrl = null): void
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
        .field {
            margin-bottom: 16px;
        }
        .field:last-of-type {
            margin-bottom: 0;
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
        .pin-input.mismatch {
            border-color: var(--danger);
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
        .terms-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-top: 16px;
            font-size: 0.84rem;
            line-height: 1.45;
            color: var(--text);
            cursor: pointer;
        }
        .terms-row input {
            margin-top: 2px;
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            accent-color: var(--brand);
        }
        .text-input {
            width: 100%;
            border: 2px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .text-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 4px rgba(7, 94, 84, 0.12);
        }
        .secret-field { position: relative; }
        .secret-field .text-input { padding-right: 52px; }
        .secret-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: var(--muted);
            padding: 6px;
            cursor: pointer;
        }
        .secret-toggle svg { display: block; width: 22px; height: 22px; }
        .secret-toggle .icon-hide { display: none; }
        .secret-toggle.is-visible .icon-show { display: none; }
        .secret-toggle.is-visible .icon-hide { display: block; }
        .sq { margin-top: 18px; }
        .sq-label {
            margin: 0 0 6px;
            font-size: 0.88rem;
            font-weight: 600;
        }
        .sq-prompt {
            margin: 0 0 12px;
            padding: 12px 14px;
            background: #f8faf9;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 0.95rem;
            line-height: 1.45;
        }
        .sq-row + .sq-row {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
        }
        .sq-add-btn {
            margin-top: 4px;
            background: transparent;
            border: 0;
            padding: 0;
            color: var(--brand);
            font-weight: 700;
            cursor: pointer;
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
        a.btn {
            display: block;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
        }
        .btn-secondary {
            background: #fff;
            color: var(--brand);
            border: 2px solid var(--brand);
            box-shadow: none;
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
            var pin = document.getElementById('pin');
            var pinConfirm = document.getElementById('pin_confirm');
            var form = document.getElementById('pin-form');
            var btn = document.getElementById('submit-btn');
            if (!pin || !form) return;

            var formMode = form.dataset.formMode || 'confirm';
            var pinCurrent = document.getElementById('pin_current');
            var acceptTerms = document.getElementById('accept_terms');

            function sanitize(el) {
                el.value = el.value.replace(/\D/g, '').slice(0, 6);
            }

            function validate() {
                if (!btn) return;
                if ((formMode === 'setup' || formMode === 'change') && pinConfirm) {
                    var match = pin.value.length === 6
                        && pinConfirm.value.length === 6
                        && pin.value === pinConfirm.value;
                    if (formMode === 'change' && pinCurrent) {
                        match = match
                            && pinCurrent.value.length === 6
                            && pinCurrent.value !== pin.value;
                    }
                    if (formMode === 'setup' && acceptTerms && !acceptTerms.checked) {
                        match = false;
                    }
                    btn.disabled = !match;
                    pinConfirm.classList.toggle(
                        'mismatch',
                        pinConfirm.value.length === 6 && pin.value !== pinConfirm.value
                    );
                } else {
                    btn.disabled = pin.value.length !== 6;
                }
            }

            pin.addEventListener('input', function () {
                sanitize(pin);
                validate();
            });

            if (pinConfirm) {
                pinConfirm.addEventListener('input', function () {
                    sanitize(pinConfirm);
                    validate();
                });
            }

            if (pinCurrent) {
                pinCurrent.addEventListener('input', function () {
                    sanitize(pinCurrent);
                    validate();
                });
            }

            if (acceptTerms) {
                acceptTerms.addEventListener('change', validate);
            }

            var securityAnswer = document.getElementById('security_answer');

            var baseValidate = validate;
            validate = function () {
                baseValidate();
                if (btn && !btn.disabled && securityAnswer && securityAnswer.value.trim() === '') {
                    btn.disabled = true;
                }
            };

            if (securityAnswer) {
                securityAnswer.addEventListener('input', validate);
            }

            form.addEventListener('click', function (event) {
                var toggle = event.target.closest('.secret-toggle');
                if (!toggle || !form.contains(toggle)) return;
                var input = toggle.parentNode.querySelector('input');
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                toggle.classList.toggle('is-visible', show);
                toggle.setAttribute('aria-label', show ? 'Hide answer' : 'Show answer');
            });

            form.addEventListener('submit', function () {
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = btn.dataset.loading || 'Confirming…';
                }
            });

            pin.focus();
        })();
    </script>
    <?php endif; ?>
    <?php if ($redirectUrl !== null && $redirectUrl !== ''): ?>
    <script>
        (function () {
            var url = <?= json_encode($redirectUrl, JSON_UNESCAPED_SLASHES) ?>;
            setTimeout(function () {
                window.location.replace(url);
            }, 1200);
        })();
    </script>
    <?php endif; ?>
</body>
</html>
    <?php
    exit;
}

function renderPinForm(
    string $token,
    string $submitLabel,
    ?string $error = null,
    string $extraHtml = '',
    string $formMode = 'confirm',
    string $securityHtml = ''
): string {
    $html = '';
    if ($error !== null && $error !== '') {
        $html .= '<div class="alert alert-error">' . h($error) . '</div>';
    }
    $html .= $extraHtml;
    $html .= '<form id="pin-form" method="post" action="confirm-pin.php" autocomplete="off"'
        . ' data-form-mode="' . h($formMode) . '">'
        . '<input type="hidden" name="token" value="' . h($token) . '">';

    if ($formMode === 'setup') {
        $html .= '<div class="field">'
            . '<label for="pin">PIN</label>'
            . '<input class="pin-input" id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required>'
            . '</div>'
            . '<div class="field">'
            . '<label for="pin_confirm">Confirm PIN</label>'
            . '<input class="pin-input" id="pin_confirm" name="pin_confirm" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required>'
            . '</div>'
            . '<label class="terms-row" for="accept_terms">'
            . '<input type="checkbox" id="accept_terms" name="accept_terms" value="1" required>'
            . '<span>I have read and agree to the <a href="https://usesendra.com/terms/" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">Terms of Service</a> and <a href="https://usesendra.com/privacy/" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">Privacy Policy</a>.</span>'
            . '</label>';
    } elseif ($formMode === 'change') {
        $html .= '<div class="field">'
            . '<label for="pin_current">Current PIN</label>'
            . '<input class="pin-input" id="pin_current" name="pin_current" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="current-password" required>'
            . '</div>'
            . '<div class="field">'
            . '<label for="pin">New PIN</label>'
            . '<input class="pin-input" id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required>'
            . '</div>'
            . '<div class="field">'
            . '<label for="pin_confirm">Confirm new PIN</label>'
            . '<input class="pin-input" id="pin_confirm" name="pin_confirm" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required>'
            . '</div>';
    } else {
        $html .= '<label for="pin">6-digit PIN</label>'
            . '<input class="pin-input" id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>';
    }

    $loading = 'Confirming…';
    if ($submitLabel === 'Set PIN') {
        $loading = 'Setting…';
    } elseif ($submitLabel === 'Change PIN') {
        $loading = 'Updating…';
    }

    $html .= $securityHtml;
    $html .= '<button class="btn" id="submit-btn" type="submit" data-loading="'
        . h($loading)
        . '" disabled>' . h($submitLabel) . '</button>'
        . '</form>';

    return $html;
}

function securitySection(SecurityQuestionService $service, string $phone, string $token, bool $requireChallenge, string $returnScript): string
{
    $postedId = (int) ($_POST['sq_id'] ?? 0);
    $postedMac = (string) ($_POST['sq_mac'] ?? '');
    $challenge = $requireChallenge
        ? $service->pickChallenge($phone, $token, $postedId > 0 ? $postedId : null, $postedMac !== '' ? $postedMac : null)
        : null;
    $saved = $service->count($phone);
    $setupUrl = null;
    if ($requireChallenge && $saved < SecurityQuestionService::MAX_QUESTIONS) {
        $setupUrl = '/security-questions.php?token=' . rawurlencode($token) . '&return=' . rawurlencode($returnScript);
    }

    return SecurityQuestionForm::renderChallenge($saved, $challenge, $setupUrl);
}

function renderSuccessPage(string $title, string $heading, string $subtitle): void
{
    renderPage(
        $title,
        '<h2>' . h($heading) . '</h2>'
        . '<p class="subtitle">' . h($subtitle) . '</p>'
        . '<div class="alert alert-success">Returning you to WhatsApp…</div>',
        null,
        PinConfirmTokenService::whatsappChatUrl()
    );
}

$securityQuestions = new SecurityQuestionService($db);
$userModel = new User($db);

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$claims = PinConfirmTokenService::verify($token);

if ($claims === null) {
    renderPage(
        'Link expired',
        '<h2>Link expired or invalid</h2>'
        . '<p class="subtitle">This link is no longer valid. Return to WhatsApp and try again.</p>'
        . '<div class="alert alert-info">Links expire after a short time for your security.</div>'
    );
}

$mode = (string) ($claims['mode'] ?? PinConfirmTokenService::MODE_CONFIRM);
$phone = (string) $claims['phone'];
$isSetup = $mode === PinConfirmTokenService::MODE_SETUP;
$isChange = $mode === PinConfirmTokenService::MODE_CHANGE;
$formMode = $isSetup ? 'setup' : ($isChange ? 'change' : 'confirm');

if ($isSetup) {
    $pageTitle = 'Set your PIN';
    $heading = 'Set your PIN';
    $subtitle = 'Choose a 6-digit PIN and enter it again to confirm.';
    $submitLabel = 'Set PIN';
    $summaryHtml = '';
} elseif ($isChange) {
    $pageTitle = 'Change your PIN';
    $heading = 'Change your PIN';
    $subtitle = 'Enter your current PIN, then choose a new 6-digit PIN.';
    $submitLabel = 'Change PIN';
    $summaryHtml = '';
} else {
    $pendingId = (string) ($claims['pending_id'] ?? '');
    $pending = $postPinService->getPendingForWeb($pendingId);

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

    $summary = $postPinService->describeForWeb($pending);
    $pageTitle = 'Confirm transaction';
    $heading = (string) $summary['title'];
    $subtitle = (string) $summary['subtitle'];
    $submitLabel = 'Confirm transaction';
    $summaryHtml = '';
    foreach ($summary['rows'] as $row) {
        $summaryHtml .= '<div class="summary-row"><span>' . h($row['label']) . '</span><strong>' . h($row['value']) . '</strong></div>';
    }
    if ($summaryHtml !== '') {
        $summaryHtml = '<div class="summary">' . $summaryHtml . '</div>';
    }
}

$requireSecurityChallenge = !$isSetup && !$isChange;
$securityHtml = securitySection($securityQuestions, $phone, $token, $requireSecurityChallenge, 'confirm-pin.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(180);
    $pin = preg_replace('/\D/', '', (string) ($_POST['pin'] ?? ''));
    $pinConfirm = preg_replace('/\D/', '', (string) ($_POST['pin_confirm'] ?? ''));
    $pinCurrent = preg_replace('/\D/', '', (string) ($_POST['pin_current'] ?? ''));
    $error = null;
    $securityPrepared = SecurityQuestionForm::prepare(
        $securityQuestions,
        $phone,
        $token,
        $requireSecurityChallenge,
        $_POST
    );

    if (empty($securityPrepared['ok'])) {
        $error = (string) ($securityPrepared['error'] ?? 'Check the security question and try again.');
    } elseif ($isChange) {
        if (!preg_match('/^\d{6}$/', $pinCurrent)) {
            $error = 'Please enter your current 6-digit PIN.';
        } elseif (!preg_match('/^\d{6}$/', $pin)) {
            $error = 'Please enter a valid 6-digit new PIN.';
        } elseif ($pin !== $pinConfirm) {
            $error = 'New PINs do not match. Please try again.';
        } else {
            $result = $pinWeb->changePin($phone, $pinCurrent, $pin);
            if (!empty($result['success'])) {
                renderSuccessPage(
                    'PIN changed',
                    'PIN updated successfully',
                    'Use your new PIN the next time you confirm a transaction.'
                );
            }
            $error = (string) ($result['error'] ?? 'Could not change PIN. Please try again.');
        }
    } elseif (!preg_match('/^\d{6}$/', $pin)) {
        $error = 'Please enter a valid 6-digit PIN.';
    } elseif ($isSetup && $pin !== $pinConfirm) {
        $error = 'PINs do not match. Please try again.';
    } elseif ($isSetup && empty($_POST['accept_terms'])) {
        $error = 'Please accept the Terms of Service and Privacy Policy to continue.';
    } elseif ($isSetup) {
        $result = $pinWeb->setupPin($phone, $pin);
        if (!empty($result['success'])) {
            renderSuccessPage(
                'PIN set',
                'PIN set successfully',
                'You can now send, sell, and swap from WhatsApp.'
            );
        }
        $error = (string) ($result['error'] ?? 'Could not set PIN. Please try again.');
    } else {
        $pendingId = (string) ($claims['pending_id'] ?? '');
        if (!$userModel->verifyPin($phone, $pin)) {
            $error = 'Wrong PIN. Please try again.';
        } else {
            $result = $postPinService->confirmWithPin($phone, $pin, $pendingId, false, true, true);
            if ($result->success) {
                renderSuccessPage(
                    'Confirmed',
                    'Transaction confirmed',
                    'Your transaction is processing — check WhatsApp for the receipt.'
                );
            }
            $error = (string) ($result->error ?? 'Wrong PIN. Please try again.');
        }
    }

    $securityHtml = securitySection($securityQuestions, $phone, $token, $requireSecurityChallenge, 'confirm-pin.php');
    renderPage(
        $pageTitle,
        '<h2>' . h($heading) . '</h2>'
        . '<p class="subtitle">' . h($subtitle) . '</p>'
        . renderPinForm($token, $submitLabel, $error, $summaryHtml, $formMode, $securityHtml),
        $token
    );
}

renderPage(
    $pageTitle,
    '<h2>' . h($heading) . '</h2>'
    . '<p class="subtitle">' . h($subtitle) . '</p>'
    . renderPinForm($token, $submitLabel, null, $summaryHtml, $formMode, $securityHtml),
    $token
);

