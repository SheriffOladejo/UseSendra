<?php

declare(strict_types=1);

/**
 * Secure web KYC verification for Sendra NGN tier upgrades.
 * Liveness uses device camera only — no gallery upload.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap_env.php';
require __DIR__ . '/config/Database.php';

use App\Models\User;
use App\Services\KycVerificationTokenService;
use App\Services\PinConfirmTokenService;
use App\Services\SendraKycService;
use App\Services\SendraKycVerificationService;
use App\Services\WhatsAppService;
use AptosV2\Bootstrap\PinConfirmBootstrap;

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function renderPage(string $title, string $bodyHtml, ?string $redirectUrl = null): void
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
        .wrap { max-width: 440px; margin: 0 auto; }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .logo {
            width: 42px; height: 42px; border-radius: 12px; background: var(--brand); color: #fff;
            display: grid; place-items: center; font-weight: 700; font-size: 18px; box-shadow: var(--shadow);
        }
        .brand h1 { margin: 0; font-size: 1.15rem; font-weight: 700; }
        .brand p { margin: 2px 0 0; font-size: 0.85rem; color: var(--muted); }
        .card {
            background: var(--card); border-radius: 18px; padding: 22px 20px;
            box-shadow: var(--shadow); border: 1px solid rgba(255,255,255,0.8);
        }
        .card h2 { margin: 0 0 6px; font-size: 1.25rem; }
        .subtitle { margin: 0 0 16px; color: var(--muted); font-size: 0.92rem; line-height: 1.45; }
        .field { margin-bottom: 14px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; }
        input, textarea, select {
            width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 12px;
            font-size: 1rem; background: #fff;
        }
        input:focus, textarea:focus, select:focus {
            outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(7,94,84,0.12);
        }
        .btn {
            width: 100%; border: none; border-radius: 12px; padding: 14px 16px; font-size: 1rem;
            font-weight: 700; cursor: pointer; background: var(--brand); color: #fff; margin-top: 8px;
        }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .btn.secondary { background: #fff; color: var(--brand); border: 1px solid var(--border); }
        .alert { border-radius: 12px; padding: 12px 14px; font-size: 0.9rem; margin-top: 12px; }
        .alert-error { background: #fef3f2; color: var(--danger); border: 1px solid #fecdca; }
        .alert-success { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
        .alert-info { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }
        .steps { display: flex; gap: 8px; margin-bottom: 16px; }
        .step-dot {
            flex: 1; height: 4px; border-radius: 999px; background: var(--border);
        }
        .step-dot.active { background: var(--brand); }
        .step-dot.done { background: var(--accent); }
        .camera-box {
            position: relative; border-radius: 16px; overflow: hidden; background: #111;
            aspect-ratio: 3/4; max-height: 320px; margin-bottom: 12px;
        }
        video, canvas, .captured {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .captured { display: none; }
        .camera-box.has-capture video { display: none; }
        .camera-box.has-capture .captured { display: block; }
        .hint { font-size: 0.82rem; color: var(--muted); line-height: 1.4; margin-bottom: 10px; }
        .hidden { display: none !important; }
    </style>
    <?php if ($redirectUrl): ?>
    <meta http-equiv="refresh" content="3;url=<?= h($redirectUrl) ?>">
    <?php endif; ?>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <div class="logo">S</div>
            <div>
                <h1>Sendra</h1>
                <p>Identity verification</p>
            </div>
        </div>
        <div class="card">
            <?= $bodyHtml ?>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$claims = KycVerificationTokenService::verify($token);

if ($claims === null && !isset($_GET['api'])) {
    renderPage(
        'Link expired',
        '<h2>Link expired or invalid</h2>'
        . '<p class="subtitle">Return to WhatsApp and tap <strong>Start verification</strong> again.</p>'
        . '<div class="alert alert-info">Links expire after 45 minutes for your security.</div>'
    );
}

$db = PinConfirmBootstrap::connectAptosDb();
$accessToken = (string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '');
$phoneNumberId = (string) ($_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID') ?: '851265871413834');
$whatsapp = new WhatsAppService($accessToken, $phoneNumberId);
$userModel = new User($db);
$kycService = new SendraKycService();
$verification = new SendraKycVerificationService();

if (isset($_GET['api']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($claims === null) {
        jsonResponse(['ok' => false, 'error' => 'Link expired. Return to WhatsApp.'], 403);
    }

    $phone = (string) $claims['phone'];
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'tier1') {
        $proofFile = null;
        if (isset($_FILES['sow_proof']) && is_array($_FILES['sow_proof'])) {
            $proofFile = [
                'tmp_name' => (string) ($_FILES['sow_proof']['tmp_name'] ?? ''),
                'name' => (string) ($_FILES['sow_proof']['name'] ?? ''),
                'size' => (int) ($_FILES['sow_proof']['size'] ?? 0),
                'type' => (string) ($_FILES['sow_proof']['type'] ?? ''),
            ];
        }

        $result = $verification->completeTier1(
            $userModel,
            $whatsapp,
            $phone,
            (string) ($_POST['nin'] ?? ''),
            (string) ($_POST['full_name'] ?? ''),
            (string) ($_POST['source_of_wealth'] ?? ''),
            $proofFile
        );
        jsonResponse($result, $result['ok'] ? 200 : 422);
    }

    if ($action === 'tier2') {
        $result = $verification->completeTier2(
            $userModel,
            $whatsapp,
            $phone,
            (string) ($_POST['bvn'] ?? ''),
            (string) ($_POST['nin'] ?? ''),
            (string) ($_POST['image'] ?? '')
        );
        jsonResponse($result, $result['ok'] ? 200 : 422);
    }

    if ($action === 'tier3') {
        $proofFile = null;
        if (isset($_FILES['proof_document']) && is_array($_FILES['proof_document'])) {
            $proofFile = [
                'tmp_name' => (string) ($_FILES['proof_document']['tmp_name'] ?? ''),
                'name' => (string) ($_FILES['proof_document']['name'] ?? ''),
                'size' => (int) ($_FILES['proof_document']['size'] ?? 0),
                'type' => (string) ($_FILES['proof_document']['type'] ?? ''),
            ];
        }

        $result = $verification->completeTier3($userModel, $whatsapp, $phone, [
            'proof_type' => (string) ($_POST['proof_type'] ?? ''),
            'first_name' => (string) ($_POST['first_name'] ?? ''),
            'last_name' => (string) ($_POST['last_name'] ?? ''),
            'address' => (string) ($_POST['address'] ?? ''),
            'house_no' => (string) ($_POST['house_no'] ?? ''),
            'state' => (string) ($_POST['state'] ?? ''),
            'lga' => (string) ($_POST['lga'] ?? ''),
        ], $proofFile);
        jsonResponse($result, $result['ok'] ? 200 : 422);
    }

    jsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
}

$phone = (string) $claims['phone'];
$user = $userModel->findByPhone($phone);
if (!is_array($user)) {
    renderPage('Account not found', '<h2>Account not found</h2><p class="subtitle">Return to WhatsApp and try again.</p>');
}

$currentTier = $kycService->getUserTier($user);
$targetTier = max((int) $claims['tier'], $kycService->nextTierToVerify($user));
$targetTier = min(SendraKycService::TIER_ADDRESS, max(SendraKycService::TIER_BVN, $targetTier));

$tierLabel = $kycService->tierLabel($targetTier);
$waUrl = PinConfirmTokenService::whatsappChatUrl();

$stepHtml = match ($targetTier) {
    SendraKycService::TIER_BVN => renderTier1Form($token),
    SendraKycService::TIER_NIN => renderTier2Form($token, $user),
    SendraKycService::TIER_ADDRESS => renderTier3Form($token, $user),
    default => '<p>Your account is already verified for this tier.</p>',
};

if ($currentTier >= $targetTier) {
    renderPage(
        'Already verified',
        '<h2>Already verified</h2>'
        . '<p class="subtitle">Your account already meets this tier. Return to WhatsApp to continue.</p>'
        . '<div class="alert alert-success">You can close this page.</div>',
        $waUrl
    );
}

renderPage(
    $tierLabel . ' verification',
    '<h2>' . h($tierLabel) . ' verification</h2>'
    . '<p class="subtitle">Complete verification securely in your browser. We only store the last 4 digits of your BVN/NIN and encrypt your address at rest.</p>'
    . $stepHtml
);

function renderTier1Form(string $token): string
{
    $sources = SendraKycVerificationService::sourceOfWealthOptions();
    ob_start();
    ?>
    <div class="steps"><div class="step-dot active"></div><div class="step-dot"></div><div class="step-dot"></div></div>
    <form id="kyc-form">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <div id="sow-step">
            <p class="hint">Tell us where your money comes from, then upload a receipt, invoice, payslip, or statement. PDF, JPG, or PNG, under 5 MB.</p>
            <div class="field">
                <label for="source_of_wealth">Source of wealth</label>
                <select id="source_of_wealth" name="source_of_wealth" required>
                    <option value="">Choose one</option>
                    <?php foreach ($sources as $value => $label): ?>
                    <option value="<?= h($value) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="sow_proof">Proof of source of wealth</label>
                <input id="sow_proof" name="sow_proof" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
            </div>
            <div id="msg-sow" class="hidden"></div>
            <button type="button" class="btn" id="sow-continue">Continue</button>
        </div>
        <div id="nin-step" class="hidden">
            <p class="hint">Your NIN phone number must match the WhatsApp number you use on Sendra.</p>
            <div class="field">
                <label for="full_name">Full legal name (as on NIN)</label>
                <input id="full_name" name="full_name" autocomplete="name" placeholder="e.g. Adaobi Chioma Okonkwo">
            </div>
            <div class="field">
                <label for="nin">NIN (11 digits)</label>
                <input id="nin" name="nin" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" autocomplete="off">
            </div>
            <p class="hint">Use every name on your NIN — order does not matter.</p>
            <div id="msg" class="hidden"></div>
            <button type="button" class="btn secondary" id="sow-back">Back</button>
            <button type="submit" class="btn" id="submit-btn">Submit Tier 1</button>
        </div>
    </form>
    <script>
    (function () {
        const form = document.getElementById('kyc-form');
        const sowStep = document.getElementById('sow-step');
        const ninStep = document.getElementById('nin-step');
        const source = document.getElementById('source_of_wealth');
        const proof = document.getElementById('sow_proof');
        const fullName = document.getElementById('full_name');
        const nin = document.getElementById('nin');
        const msgSow = document.getElementById('msg-sow');
        const msg = document.getElementById('msg');
        const btn = document.getElementById('submit-btn');

        function showMsg(el, text, ok) {
            el.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
            el.textContent = text;
            el.classList.remove('hidden');
        }

        document.getElementById('sow-continue').addEventListener('click', function () {
            if (!source.value) {
                showMsg(msgSow, 'Choose your source of wealth.', false);
                return;
            }
            if (!proof.files || !proof.files.length) {
                showMsg(msgSow, 'Upload a receipt, invoice, payslip, or statement.', false);
                return;
            }
            msgSow.classList.add('hidden');
            sowStep.classList.add('hidden');
            ninStep.classList.remove('hidden');
        });

        document.getElementById('sow-back').addEventListener('click', function () {
            ninStep.classList.add('hidden');
            sowStep.classList.remove('hidden');
        });

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (!fullName.value.trim()) {
                showMsg(msg, 'Enter your full legal name first.', false);
                return;
            }
            if (!/^[0-9]{11}$/.test(nin.value.trim())) {
                showMsg(msg, 'Enter your 11-digit NIN.', false);
                return;
            }
            btn.disabled = true;
            const body = new FormData(form);
            body.append('action', 'tier1');
            try {
                const res = await fetch('?api=1', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    showMsg(msg, 'Tier 1 verified! Returning to WhatsApp…', true);
                    setTimeout(function () { window.location.href = <?= json_encode(PinConfirmTokenService::whatsappChatUrl()) ?>; }, 2000);
                } else {
                    showMsg(msg, data.error || 'Verification failed.', false);
                    btn.disabled = false;
                }
            } catch (err) {
                showMsg(msg, 'Network error. Try again.', false);
                btn.disabled = false;
            }
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

function renderTier2Form(string $token, array $user): string
{
    $hasNin = !empty($user['nin_last4']) || !empty($user['nin']);
    $hasBvn = !empty($user['bvn_last4']) || !empty($user['bvn']);

    if ($hasBvn && !$hasNin) {
        return renderTier2NinLegacyForm($token);
    }

    return renderTier2BvnLivenessForm($token);
}

function renderTier2BvnLivenessForm(string $token): string
{
    ob_start();
    ?>
    <div class="steps"><div class="step-dot done"></div><div class="step-dot active"></div><div class="step-dot"></div></div>
    <p class="hint">Your BVN phone number must match your Sendra WhatsApp number. Use your front camera only — gallery uploads are not allowed.</p>
    <div class="field">
        <label for="bvn">BVN (11 digits)</label>
        <input id="bvn" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" required autocomplete="off">
    </div>
    <div class="camera-box" id="camera-box">
        <video id="video" playsinline autoplay muted></video>
        <img id="preview" class="captured" alt="Captured face">
    </div>
    <button type="button" class="btn secondary" id="start-camera">Open camera</button>
    <button type="button" class="btn secondary hidden" id="retake">Retake photo</button>
    <button type="button" class="btn hidden" id="capture">Capture photo</button>
    <input type="hidden" id="token" value="<?= h($token) ?>">
    <div id="msg" class="hidden"></div>
    <button type="button" class="btn hidden" id="submit-tier2">Submit Tier 2</button>
    <script>
    (function () {
        const video = document.getElementById('video');
        const preview = document.getElementById('preview');
        const box = document.getElementById('camera-box');
        const startBtn = document.getElementById('start-camera');
        const captureBtn = document.getElementById('capture');
        const retakeBtn = document.getElementById('retake');
        const submitBtn = document.getElementById('submit-tier2');
        const msg = document.getElementById('msg');
        let stream = null;
        let captured = '';

        function showMsg(text, ok) {
            msg.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
            msg.textContent = text;
            msg.classList.remove('hidden');
        }

        async function startCamera() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 960 } },
                    audio: false
                });
                video.srcObject = stream;
                startBtn.classList.add('hidden');
                captureBtn.classList.remove('hidden');
            } catch (e) {
                showMsg('Camera access is required for liveness. Allow camera permission and try again.', false);
            }
        }

        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(function (t) { t.stop(); });
                stream = null;
            }
        }

        startBtn.addEventListener('click', startCamera);

        captureBtn.addEventListener('click', function () {
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth || 720;
            canvas.height = video.videoHeight || 960;
            canvas.getContext('2d').drawImage(video, 0, 0);
            captured = canvas.toDataURL('image/jpeg', 0.88);
            preview.src = captured;
            box.classList.add('has-capture');
            stopCamera();
            captureBtn.classList.add('hidden');
            retakeBtn.classList.remove('hidden');
            submitBtn.classList.remove('hidden');
        });

        retakeBtn.addEventListener('click', function () {
            captured = '';
            box.classList.remove('has-capture');
            retakeBtn.classList.add('hidden');
            submitBtn.classList.add('hidden');
            startCamera();
        });

        submitBtn.addEventListener('click', async function () {
            const bvn = document.getElementById('bvn').value.replace(/\D/g, '');
            if (bvn.length !== 11) {
                showMsg('Enter a valid 11-digit BVN.', false);
                return;
            }
            if (!captured) {
                showMsg('Capture a live photo first.', false);
                return;
            }
            submitBtn.disabled = true;
            const body = new FormData();
            body.append('action', 'tier2');
            body.append('token', document.getElementById('token').value);
            body.append('bvn', bvn);
            body.append('image', captured);
            try {
                const res = await fetch('?api=1', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    showMsg('Tier 2 verified! Returning to WhatsApp…', true);
                    setTimeout(function () { window.location.href = <?= json_encode(PinConfirmTokenService::whatsappChatUrl()) ?>; }, 2000);
                } else {
                    showMsg(data.error || 'Verification failed.', false);
                    submitBtn.disabled = false;
                }
            } catch (e) {
                showMsg('Network error. Try again.', false);
                submitBtn.disabled = false;
            }
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

function renderTier2NinLegacyForm(string $token): string
{
    ob_start();
    ?>
    <div class="steps"><div class="step-dot done"></div><div class="step-dot active"></div><div class="step-dot"></div></div>
    <p class="hint">Your NIN phone number must match your Sendra WhatsApp number. Use your front camera only — gallery uploads are not allowed.</p>
    <div class="field">
        <label for="nin">NIN (11 digits)</label>
        <input id="nin" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" required autocomplete="off">
    </div>
    <div class="camera-box" id="camera-box">
        <video id="video" playsinline autoplay muted></video>
        <img id="preview" class="captured" alt="Captured face">
    </div>
    <button type="button" class="btn secondary" id="start-camera">Open camera</button>
    <button type="button" class="btn secondary hidden" id="retake">Retake photo</button>
    <button type="button" class="btn hidden" id="capture">Capture photo</button>
    <input type="hidden" id="token" value="<?= h($token) ?>">
    <div id="msg" class="hidden"></div>
    <button type="button" class="btn hidden" id="submit-tier2">Submit Tier 2</button>
    <script>
    (function () {
        const video = document.getElementById('video');
        const preview = document.getElementById('preview');
        const box = document.getElementById('camera-box');
        const startBtn = document.getElementById('start-camera');
        const captureBtn = document.getElementById('capture');
        const retakeBtn = document.getElementById('retake');
        const submitBtn = document.getElementById('submit-tier2');
        const msg = document.getElementById('msg');
        let stream = null;
        let captured = '';

        function showMsg(text, ok) {
            msg.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
            msg.textContent = text;
            msg.classList.remove('hidden');
        }

        async function startCamera() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 960 } },
                    audio: false
                });
                video.srcObject = stream;
                startBtn.classList.add('hidden');
                captureBtn.classList.remove('hidden');
            } catch (e) {
                showMsg('Camera access is required for liveness. Allow camera permission and try again.', false);
            }
        }

        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(function (t) { t.stop(); });
                stream = null;
            }
        }

        startBtn.addEventListener('click', startCamera);

        captureBtn.addEventListener('click', function () {
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth || 720;
            canvas.height = video.videoHeight || 960;
            canvas.getContext('2d').drawImage(video, 0, 0);
            captured = canvas.toDataURL('image/jpeg', 0.88);
            preview.src = captured;
            box.classList.add('has-capture');
            stopCamera();
            captureBtn.classList.add('hidden');
            retakeBtn.classList.remove('hidden');
            submitBtn.classList.remove('hidden');
        });

        retakeBtn.addEventListener('click', function () {
            captured = '';
            box.classList.remove('has-capture');
            retakeBtn.classList.add('hidden');
            submitBtn.classList.add('hidden');
            startCamera();
        });

        submitBtn.addEventListener('click', async function () {
            const nin = document.getElementById('nin').value.replace(/\D/g, '');
            if (nin.length !== 11) {
                showMsg('Enter a valid 11-digit NIN.', false);
                return;
            }
            if (!captured) {
                showMsg('Capture a live photo first.', false);
                return;
            }
            submitBtn.disabled = true;
            const body = new FormData();
            body.append('action', 'tier2');
            body.append('token', document.getElementById('token').value);
            body.append('nin', nin);
            body.append('image', captured);
            try {
                const res = await fetch('?api=1', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    showMsg('Tier 2 verified! Returning to WhatsApp…', true);
                    setTimeout(function () { window.location.href = <?= json_encode(PinConfirmTokenService::whatsappChatUrl()) ?>; }, 2000);
                } else {
                    showMsg(data.error || 'Verification failed.', false);
                    submitBtn.disabled = false;
                }
            } catch (e) {
                showMsg('Network error. Try again.', false);
                submitBtn.disabled = false;
            }
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

/** @param array<string, mixed> $user */
function renderTier3Form(string $token, array $user): string
{
    $fullName = trim((string) ($user['kyc_full_name'] ?? ''));
    $parts = $fullName !== '' ? preg_split('/\s+/', $fullName, 2) : ['', ''];

    ob_start();
    ?>
    <div class="steps"><div class="step-dot done"></div><div class="step-dot done"></div><div class="step-dot active"></div></div>
    <p class="hint">Upload a recent utility bill or bank statement that shows your address, then enter the same address manually below. We encrypt your address and do not keep the uploaded document.</p>
    <form id="kyc-form" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <div class="field">
            <label for="proof_type">Proof of address type</label>
            <select id="proof_type" name="proof_type" required>
                <option value="">Select document type</option>
                <option value="utility_bill">Utility bill</option>
                <option value="bank_statement">Bank statement</option>
            </select>
        </div>
        <div class="field">
            <label for="proof_document">Upload document (PDF, JPG, or PNG — max 5 MB)</label>
            <input id="proof_document" name="proof_document" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
        </div>
        <div class="field">
            <label for="first_name">First name</label>
            <input id="first_name" name="first_name" value="<?= h($parts[0] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="last_name">Last name</label>
            <input id="last_name" name="last_name" value="<?= h($parts[1] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="house_no">House / flat number</label>
            <input id="house_no" name="house_no" required placeholder="e.g. 12A">
        </div>
        <div class="field">
            <label for="address">Street address</label>
            <textarea id="address" name="address" rows="2" required placeholder="Street name and area"></textarea>
        </div>
        <div class="field">
            <label for="lga">LGA</label>
            <input id="lga" name="lga" required>
        </div>
        <div class="field">
            <label for="state">State</label>
            <input id="state" name="state" required>
        </div>
        <div id="msg" class="hidden"></div>
        <button type="submit" class="btn" id="submit-btn">Submit proof of address</button>
    </form>
    <script>
    (function () {
        const form = document.getElementById('kyc-form');
        const msg = document.getElementById('msg');
        const btn = document.getElementById('submit-btn');

        function showMsg(text, ok) {
            msg.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
            msg.textContent = text;
            msg.classList.remove('hidden');
        }

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            btn.disabled = true;
            const body = new FormData(form);
            body.append('action', 'tier3');
            try {
                const res = await fetch('?api=1', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    showMsg('Address verified! Returning to WhatsApp…', true);
                    setTimeout(function () { window.location.href = <?= json_encode(PinConfirmTokenService::whatsappChatUrl()) ?>; }, 2000);
                } else {
                    showMsg(data.error || 'Submission failed.', false);
                    btn.disabled = false;
                }
            } catch (err) {
                showMsg('Network error. Try again.', false);
                btn.disabled = false;
            }
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
