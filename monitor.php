<?php
// monitor.php — Transaction anomaly monitor
// Crontab: */5 * * * * php /var/www/html/coinectra/monitor.php >> /var/log/sendra-monitor.log 2>&1

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/Database.php';

use App\Services\WhatsAppService;

// ── Config ────────────────────────────────────────────────────────────────────
$_ENV['INTERNAL_API_SECRET'] = 'string';
$ACCESS_TOKEN    = "EAAnBMaDNtoUBQeFAjBMFryee3LifVRqjB4NkecAPZAnXKRKcUZA2u9JcilTaICwqa7qJJWNuQctZCH020Ir1SVzO33UcCkuP8s5H45BpyIrZAQDKnJVDS33qZAt0IlOptKZB7aO5ZBytSzYgTTMLFI0e4yrtpp0MyGqkMKbSw4bvMPUA5l91ZCUEZBb4saoChLQZDZD";
$PHONE_NUMBER_ID = "851265871413834";
$ADMIN_PHONE     = "2348164727987";   // your WhatsApp number
$ADMIN_EMAIL     = "sherifffoladejo@gmail.com";
$FROM_EMAIL      = "hello@usesendra.com";
$FROM_NAME       = "Sendra Monitor";

$db              = Database::getInstance()->getConnection();
$whatsappService = new WhatsAppService($ACCESS_TOKEN, $PHONE_NUMBER_ID);

$alertCount = 0;
$allAlerts  = [];

// ── Alert dispatcher ──────────────────────────────────────────────────────────
function alert($whatsappService, $adminPhone, $subject, $body) {
    global $ADMIN_EMAIL, $FROM_EMAIL, $FROM_NAME, $alertCount, $allAlerts;

    $alertCount++;
    $allAlerts[] = ['subject' => $subject, 'body' => $body];

    error_log("MONITOR ALERT [$subject]: $body");

    // WhatsApp alert
    $whatsappService->sendMessage($adminPhone,
        "🚨 *Sendra Alert*\n*{$subject}*\n\n{$body}"
    );

    // Email alert
    sendAlertEmail($ADMIN_EMAIL, $FROM_EMAIL, $FROM_NAME, $subject, $body);
}

function sendAlertEmail($to, $from, $fromName, $subject, $body) {
    $htmlBody = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
    <div style="background:#d32f2f;padding:20px 24px;">
      <h2 style="color:#fff;margin:0;font-size:20px;">🚨 Sendra Alert</h2>
    </div>
    <div style="padding:24px;">
      <h3 style="color:#333;margin-top:0;">' . htmlspecialchars($subject) . '</h3>
      <p style="color:#555;line-height:1.6;white-space:pre-wrap;">' . htmlspecialchars($body) . '</p>
      <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
      <p style="color:#999;font-size:12px;">
        Triggered at ' . date('Y-m-d H:i:s') . ' UTC<br>
        <a href="https://coinectra.stipendly.app" style="color:#1565c0;">Sendra Dashboard</a>
      </p>
    </div>
  </div>
</body>
</html>';

    // Use sendmail directly with -f flag to set envelope sender (critical for DKIM signing)
    $sendmail = "/usr/sbin/sendmail -t -f {$from}";
    $rawEmail  = "From: {$fromName} <{$from}>\r\n";
    $rawEmail .= "To: {$to}\r\n";
    $rawEmail .= "Subject: {$subject}\r\n";
    $rawEmail .= "MIME-Version: 1.0\r\n";
    $rawEmail .= "Content-Type: text/html; charset=UTF-8\r\n";
    $rawEmail .= "X-Priority: 1\r\n";
    $rawEmail .= "\r\n";
    $rawEmail .= $htmlBody;

    $handle = popen($sendmail, 'w');
    if ($handle === false) {
        error_log("MONITOR: popen sendmail failed");
        return false;
    }
    fwrite($handle, $rawEmail);
    $exitCode = pclose($handle);

    if ($exitCode !== 0) {
        error_log("MONITOR: sendmail exited with code $exitCode for $to");
        return false;
    }

    error_log("MONITOR: Alert email dispatched to $to via sendmail — $subject");
    return true;
}

// ── Check 1: Large transactions over $500 in last 5 min ──────────────────────
$stmt = $db->query("
    SELECT user_phone, direction, amount, currency, tx_hash, created_at
    FROM transaction_history
    WHERE amount > 500
    AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
    $dir     = $tx['direction'] === 'out' ? 'sent' : 'received';
    $short   = substr($tx['tx_hash'], 0, 10) . '...' . substr($tx['tx_hash'], -6);
    $time    = $tx['created_at'];
    alert($whatsappService, $ADMIN_PHONE,
        "Large Transaction Alert",
        "{$tx['user_phone']} {$dir} {$tx['amount']} {$tx['currency']}\nHash: {$short}\nTime: {$time}"
    );
}

// ── Check 2: Velocity — 5+ sends from one user in 10 min ─────────────────────
$stmt = $db->query("
    SELECT user_phone, COUNT(*) as cnt, SUM(amount) as total, MAX(currency) as currency
    FROM transaction_history
    WHERE direction = 'out'
    AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    GROUP BY user_phone
    HAVING cnt >= 5
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    alert($whatsappService, $ADMIN_PHONE,
        "High Velocity Alert",
        "{$row['user_phone']} made {$row['cnt']} sends totalling {$row['total']} {$row['currency']} in 10 minutes"
    );
}

// ── Check 3: Failed transaction spike — 3+ failures in 5 min ─────────────────
$stmt = $db->query("
    SELECT COUNT(*) as cnt FROM transaction_history
    WHERE status = 'failed'
    AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row['cnt'] >= 3) {
    alert($whatsappService, $ADMIN_PHONE,
        "Failed Transaction Spike",
        "{$row['cnt']} transactions failed in the last 5 minutes. Check aptos-service logs."
    );
}

// ── Check 4: aptos-service health ────────────────────────────────────────────
$ch = curl_init('http://127.0.0.1:3001/health');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$healthResponse = curl_exec($ch);
$healthCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr        = curl_errno($ch);
curl_close($ch);

if ($curlErr || $healthCode !== 200) {
    alert($whatsappService, $ADMIN_PHONE,
        "aptos-service Down",
        "Health check failed — HTTP {$healthCode}. Transactions will not process until the service is restarted.\n\nRun: pm2 restart aptos-service"
    );
}

$timestamp = date('Y-m-d H:i:s');
echo "{$timestamp} — Monitor run complete. Alerts fired: {$alertCount}\n";