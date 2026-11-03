<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Scores Aptos wallet activity, flags transactions that must be filed on the
 * SCUML dashboard, and emails the configured recipients once per flag.
 *
 * A transaction is SCUML-reportable when it is above 10,000 USD or 10,000 USDC,
 * above ₦5,000,000 for an individual, or above ₦10,000,000 for a business.
 */
final class SendraTxMonitorService
{
    public const SCUML_USD = 10000.0;

    public const SCUML_NGN_INDIVIDUAL = 5000000.0;

    public const SCUML_NGN_BUSINESS = 10000000.0;

    /** @var array<string, bool> */
    private array $businessCache = [];

    /** @var array<string, true>|null */
    private ?array $userColumnMap = null;

    public function __construct(private PDO $db) {}

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $this->ensureSchema();
        $emailed = $this->scan();

        $band = trim((string) ($query['band'] ?? 'attention'));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $search = trim((string) ($query['search'] ?? ''));

        $where = '1=1';
        $params = [];
        if ($band === 'scuml') {
            $where .= ' AND m.scuml_reportable = 1';
        } elseif ($band === 'attention') {
            $where .= ' AND (m.risk_score >= 6 OR m.scuml_reportable = 1)';
        } elseif ($band === 'unreported') {
            $where .= ' AND m.scuml_reportable = 1 AND m.scuml_reported_at IS NULL';
        }

        if ($search !== '') {
            $where .= ' AND (m.user_phone LIKE :q OR m.source_id LIKE :q OR m.details LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM sendra_tx_monitors m WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT m.*
            FROM sendra_tx_monitors m
            WHERE {$where}
            ORDER BY m.risk_score DESC, m.occurred_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $phones = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['user_phone'],
            $rows
        )));
        $kyc = $this->kycByPhone($phones);

        return [
            'stats' => $this->stats(),
            'recipients' => $this->recipients(),
            'scannedEmailsSent' => $emailed,
            'holds' => $this->activeHolds(),
            'pagination' => [
                'page' => (int) floor($offset / $limit) + 1,
                'pageSize' => $limit,
                'total' => $total,
                'totalPages' => (int) max(1, ceil($total / $limit)),
            ],
            'items' => array_map(fn (array $row): array => $this->present($row, $kyc[(string) $row['user_phone']] ?? null), $rows),
        ];
    }

    /**
     * @param list<string> $emails
     * @return list<string>
     */
    public function setRecipients(array $emails): array
    {
        $this->ensureSchema();
        $clean = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $clean[$email] = $email;
            }
        }

        $this->db->exec('DELETE FROM sendra_monitor_recipients');
        $insert = $this->db->prepare('INSERT INTO sendra_monitor_recipients (email) VALUES (:email)');
        foreach ($clean as $email) {
            $insert->execute(['email' => $email]);
        }

        return array_values($clean);
    }

    public function markReported(int $id): bool
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            'UPDATE sendra_tx_monitors SET scuml_reported_at = NOW() WHERE id = :id AND scuml_reportable = 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function scan(): int
    {
        $this->ensureSchema();
        $nearDay = $this->structuringWindow(1);
        $nearMonth = $this->structuringWindow(30);
        $this->holdNearLimit($nearDay['bursts']);
        $structuring = [];
        foreach ($nearDay['counts'] as $phone => $count) {
            if ($count > 5) {
                $structuring[(string) $phone] = $count;
            }
        }
        $irregular = $this->irregularByPhone($nearMonth['counts']);
        $emailed = 0;

        foreach ($this->pendingCandidates() as $row) {
            $emailed += $this->upsertPending($row, $structuring, $irregular);
        }
        foreach ($this->historyCandidates() as $row) {
            $emailed += $this->upsertHistory($row, $structuring, $irregular);
        }

        return $emailed;
    }

    /**
     * WhatsApp text when this phone cannot start a transfer. Null when the hold has ended.
     */
    public static function blockMessage(PDO $db, string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        try {
            $stmt = $db->prepare(
                'SELECT ends_at FROM sendra_activity_holds
                 WHERE user_phone = :phone AND ends_at > NOW()
                 ORDER BY ends_at DESC
                 LIMIT 1'
            );
            $stmt->execute(['phone' => $phone]);
            $endsAt = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_string($endsAt) || $endsAt === '') {
            return null;
        }

        $until = (new \DateTimeImmutable($endsAt))->format('g:i A, j M Y');

        return "⏸️ Transfers are paused until *{$until}* so we can review recent activity.\n\n"
            . 'You can continue automatically after that. You don\'t need to do anything.';
    }

    /**
     * @param array<string, int> $structuring
     * @param array<string, array{score: int, reasons: list<string>}> $irregular
     */
    private function upsertPending(array $row, array $structuring, array $irregular): int
    {
        $meta = $this->decodeMeta($row['human_description'] ?? null);
        $amount = (float) ($row['amount'] ?? 0);
        $currency = strtoupper((string) ($row['currency'] ?? ''));
        $usd = $this->usdEquivalent($amount, $currency, $meta);
        $ngn = $this->nairaAmount($amount, $currency, $meta);
        $phone = (string) $row['user_phone'];
        $score = $this->score($usd, $structuring[$phone] ?? 0, $irregular[$phone] ?? null);
        $scuml = $this->applyScuml($score, $usd, $ngn, $phone);
        if ($score['score'] < 3 && !$scuml) {
            return 0;
        }

        $txType = (string) ($row['tx_type'] ?? '');
        $activity = $this->activityType($txType);
        [$source, $destination] = $this->chainEndpoints($meta, (string) ($row['recipient_address'] ?? ''), $activity);
        $details = $this->details($activity, $amount, $currency, $meta, [
            'source_address' => $source,
            'destination_address' => $destination,
            'tx_hash' => (string) ($row['tx_hash'] ?? ''),
            'tx_type' => $txType,
        ]);

        return $this->persist([
            'source_table' => 'pending_transactions',
            'source_id' => (string) $row['id'],
            'user_phone' => $phone,
            'activity_type' => $activity,
            'status' => (string) ($row['status'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
            'usd_equivalent' => round($usd, 2),
            'risk_score' => $score['score'],
            'scuml_reportable' => $scuml ? 1 : 0,
            'reasons' => $score['reasons'],
            'details' => $details,
            'occurred_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    /**
     * @param array<string, int> $structuring
     * @param array<string, array{score: int, reasons: list<string>}> $irregular
     */
    private function upsertHistory(array $row, array $structuring, array $irregular): int
    {
        $amount = (float) ($row['amount'] ?? 0);
        $currency = strtoupper((string) ($row['currency'] ?? ''));
        $usd = $this->usdEquivalent($amount, $currency, []);
        $ngn = $this->nairaAmount($amount, $currency, []);
        $phone = (string) $row['user_phone'];
        $score = $this->score($usd, $structuring[$phone] ?? 0, $irregular[$phone] ?? null);
        $scuml = $this->applyScuml($score, $usd, $ngn, $phone);
        if ($score['score'] < 3 && !$scuml) {
            return 0;
        }

        $direction = (string) ($row['direction'] ?? 'out');
        $own = (string) ($row['aptos_address'] ?? '');
        $other = (string) ($row['counterparty'] ?? '');
        $details = $this->details($direction === 'in' ? 'deposit' : 'withdrawal', $amount, $currency, [], [
            'source_address' => $direction === 'in' ? $other : $own,
            'destination_address' => $direction === 'in' ? $own : $other,
            'tx_hash' => (string) ($row['tx_hash'] ?? ''),
            'explorer_url' => (string) ($row['explorer_url'] ?? ''),
            'chain' => (string) ($row['chain'] ?? ''),
            'tx_type' => 'onchain',
        ]);

        return $this->persist([
            'source_table' => 'transaction_history',
            'source_id' => (string) $row['id'],
            'user_phone' => $phone,
            'activity_type' => $direction === 'in' ? 'deposit' : 'withdrawal',
            'status' => (string) ($row['status'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
            'usd_equivalent' => round($usd, 2),
            'risk_score' => $score['score'],
            'scuml_reportable' => $scuml ? 1 : 0,
            'reasons' => $score['reasons'],
            'details' => $details,
            'occurred_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persist(array $row): int
    {
        $reasons = json_encode($row['reasons'], JSON_UNESCAPED_UNICODE);
        $details = json_encode($row['details'], JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare(
            'INSERT INTO sendra_tx_monitors
                (source_table, source_id, user_phone, activity_type, status, amount, currency,
                 usd_equivalent, risk_score, scuml_reportable, reasons, details, occurred_at)
             VALUES
                (:source_table, :source_id, :user_phone, :activity_type, :status, :amount, :currency,
                 :usd_equivalent, :risk_score, :scuml_reportable, :reasons, :details, :occurred_at)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                usd_equivalent = VALUES(usd_equivalent),
                risk_score = GREATEST(risk_score, VALUES(risk_score)),
                scuml_reportable = GREATEST(scuml_reportable, VALUES(scuml_reportable)),
                reasons = VALUES(reasons),
                details = VALUES(details)'
        );
        $stmt->execute([
            'source_table' => $row['source_table'],
            'source_id' => $row['source_id'],
            'user_phone' => $row['user_phone'],
            'activity_type' => $row['activity_type'],
            'status' => $row['status'],
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'usd_equivalent' => $row['usd_equivalent'],
            'risk_score' => $row['risk_score'],
            'scuml_reportable' => $row['scuml_reportable'],
            'reasons' => $reasons,
            'details' => $details,
            'occurred_at' => $row['occurred_at'],
        ]);

        $idStmt = $this->db->prepare(
            'SELECT id, risk_score, scuml_reportable, email_sent_at FROM sendra_tx_monitors
             WHERE source_table = :source_table AND source_id = :source_id LIMIT 1'
        );
        $idStmt->execute([
            'source_table' => $row['source_table'],
            'source_id' => $row['source_id'],
        ]);
        $saved = $idStmt->fetch();
        if (!is_array($saved) || $saved['email_sent_at'] !== null) {
            return 0;
        }

        $score = (int) $saved['risk_score'];
        $scuml = (int) $saved['scuml_reportable'] === 1;
        if ($score < 6 && !$scuml) {
            return 0;
        }

        if (!$this->sendAlert($row, $score, $scuml)) {
            return 0;
        }

        $mark = $this->db->prepare('UPDATE sendra_tx_monitors SET email_sent_at = NOW() WHERE id = :id');
        $mark->execute(['id' => (int) $saved['id']]);

        return 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sendAlert(array $row, int $score, bool $scuml): bool
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            error_log('SendraTxMonitor: no alert recipients configured');

            return false;
        }

        $details = is_array($row['details'] ?? null) ? $row['details'] : [];
        $subject = $scuml
            ? 'Sendra SCUML report required'
            : 'Sendra risk flag — score ' . $score;

        $lines = [
            'Phone: ' . $row['user_phone'],
            'Type: ' . $row['activity_type'],
            'Status: ' . $row['status'],
            'Amount: ' . $row['amount'] . ' ' . $row['currency'],
            'USD equivalent: ' . $row['usd_equivalent'],
            'Risk score: ' . $score . ' / 10',
            'When: ' . $row['occurred_at'],
        ];
        $reasons = is_array($row['reasons'] ?? null) ? $row['reasons'] : [];
        if ($reasons !== []) {
            $lines[] = '';
            $lines[] = 'Why it was flagged:';
            foreach ($reasons as $reason) {
                $lines[] = '- ' . $reason;
            }
        }
        $lines[] = '';
        $lines[] = $this->detailText($details);
        if ($scuml) {
            $lines[] = '';
            $lines[] = 'File this transaction on the SCUML dashboard. The Aptos admin monitoring page can be marked once that filing is done.';
        }
        $lines[] = '';
        $lines[] = 'https://usesendra.com/aptos/admin/monitoring';

        $body = implode("\n", $lines);
        $from = trim((string) ($_ENV['SENDRA_ALERT_FROM'] ?? getenv('SENDRA_ALERT_FROM') ?: 'hello@usesendra.com'));
        $ok = false;
        foreach ($recipients as $to) {
            if ($this->sendMail($to, $from, $subject, $body)) {
                $ok = true;
            }
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function detailText(array $details): string
    {
        $blocks = [];
        $off = $details['offramp'] ?? null;
        if (is_array($off)) {
            $blocks[] = "Off-ramp\n"
                . 'Account name: ' . ($off['account_name'] ?? '—') . "\n"
                . 'Account number: ' . ($off['account_number'] ?? '—') . "\n"
                . 'Bank: ' . ($off['bank_name'] ?? '—') . "\n"
                . 'Fiat: ' . ($off['fiat_amount'] ?? '—') . ' ' . ($off['fiat_currency'] ?? '') . "\n"
                . 'Crypto: ' . ($off['crypto_amount'] ?? '—') . ' ' . ($off['crypto_currency'] ?? '') . "\n"
                . 'Rate: ' . ($off['conversion_rate'] ?? '—') . ' ' . ($off['conversion_rate_label'] ?? '');
        }
        $on = $details['onramp'] ?? null;
        if (is_array($on)) {
            $blocks[] = "On-ramp\n"
                . 'Pay: ' . ($on['fiat_amount'] ?? '—') . ' ' . ($on['fiat_currency'] ?? '') . "\n"
                . 'Receive: ' . ($on['crypto_amount'] ?? '—') . ' ' . ($on['crypto_currency'] ?? '') . "\n"
                . 'Rate: ' . ($on['conversion_rate'] ?? '—') . ' ' . ($on['conversion_rate_label'] ?? '') . "\n"
                . 'Virtual account: ' . ($on['account_name'] ?? '—') . ' ' . ($on['account_number'] ?? '') . ' ' . ($on['bank_name'] ?? '');
        }
        $chain = $details['onchain'] ?? null;
        if (is_array($chain)) {
            $blocks[] = "On-chain\n"
                . 'Network: ' . ($chain['network'] ?? '—') . "\n"
                . 'Source: ' . ($chain['source_address'] ?? '—') . "\n"
                . 'Destination: ' . ($chain['destination_address'] ?? '—') . "\n"
                . 'Amount: ' . ($chain['amount'] ?? '—') . ' ' . ($chain['currency'] ?? '') . "\n"
                . 'Tx: ' . ($chain['tx_hash'] ?? '—');
        }

        return $blocks === [] ? 'No ramp or chain details stored on this row.' : implode("\n\n", $blocks);
    }

    private function sendMail(string $to, string $from, string $subject, string $body): bool
    {
        $fromName = 'Sendra Monitoring';
        $html = '<pre style="font-family:Arial,sans-serif;white-space:pre-wrap;">'
            . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre>';
        $raw = "From: {$fromName} <{$from}>\r\n";
        $raw .= "To: {$to}\r\n";
        $raw .= 'Subject: ' . $this->encodeSubject($subject) . "\r\n";
        $raw .= "MIME-Version: 1.0\r\n";
        $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $raw .= $html;

        $handle = popen('/usr/sbin/sendmail -t -f ' . escapeshellarg($from), 'w');
        if ($handle === false) {
            error_log('SendraTxMonitor: sendmail failed to start');

            return false;
        }
        fwrite($handle, $raw);
        $code = pclose($handle);
        if ($code !== 0) {
            error_log('SendraTxMonitor: sendmail exit ' . $code . ' for ' . $to);

            return false;
        }

        return true;
    }

    private function encodeSubject(string $subject): string
    {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    /**
     * @param array{score: int, reasons: list<string>} $score
     */
    private function applyScuml(array &$score, float $usd, float $ngn, string $phone): bool
    {
        $business = $this->isBusiness($phone);
        $limit = $business ? self::SCUML_NGN_BUSINESS : self::SCUML_NGN_INDIVIDUAL;
        $hits = [];
        if ($usd > self::SCUML_USD) {
            $hits[] = 'Value is above 10,000 USD or 10,000 USDC.';
        }
        if ($ngn > $limit) {
            $who = $business ? 'a business' : 'an individual';
            $hits[] = 'Naira value is above ₦' . number_format($limit, 0, '.', ',') . ' for ' . $who . '.';
        }
        if ($hits === []) {
            return false;
        }

        $score['score'] = 10;
        $score['reasons'][] = implode(' ', $hits) . ' File this on the SCUML dashboard.';

        return true;
    }

    private function isBusiness(string $phone): bool
    {
        if (array_key_exists($phone, $this->businessCache)) {
            return $this->businessCache[$phone];
        }

        $cols = $this->userColumns();
        $column = isset($cols['customer_type']) ? 'customer_type' : (isset($cols['account_type']) ? 'account_type' : null);
        $business = false;
        if ($column !== null) {
            $stmt = $this->db->prepare("SELECT {$column} FROM users WHERE phone_number = :phone LIMIT 1");
            $stmt->execute(['phone' => $phone]);
            $kind = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
            $business = in_array($kind, ['business', 'corporate', 'company'], true);
        }

        $this->businessCache[$phone] = $business;

        return $business;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function nairaAmount(float $amount, string $currency, array $meta): float
    {
        $ngn = $currency === 'NGN' ? $amount : 0.0;

        return max($ngn, $this->firstNumber($meta, [
            'target_receive_ngn',
            'sendra_quoted_receive_ngn',
            'pay_ngn',
            'instruct_ngn',
        ]));
    }

    /**
     * @param array{score: int, reasons: list<string>}|null $irregular
     * @return array{score: int, reasons: list<string>}
     */
    private function score(float $usd, int $nearLimitCount, ?array $irregular = null): array
    {
        $reasons = [];
        $size = 0;
        if ($usd > self::SCUML_USD) {
            $size = 10;
        } elseif ($usd >= 5000) {
            $size = 8;
            $reasons[] = 'Single transaction is at least 5,000 USD equivalent.';
        } elseif ($usd >= 1000) {
            $size = 6;
            $reasons[] = 'Single transaction is at least 1,000 USD equivalent.';
        } elseif ($usd >= 100) {
            $size = 3;
        }

        $pattern = $nearLimitCount > 5 ? $this->structuringScore($nearLimitCount) : 0;
        if ($pattern >= 6) {
            $reasons[] = $nearLimitCount . ' off-ramps between ₦40,000 and ₦49,999 in the last 1 day. '
                . 'Transfers are paused for 1 day, then resume automatically.';
        }

        $irregularScore = (int) ($irregular['score'] ?? 0);
        foreach ($irregular['reasons'] ?? [] as $reason) {
            $reasons[] = $reason;
        }

        $score = max($size, $pattern, $irregularScore);
        if ($score >= 9) {
            $reasons[] = 'Score is 9 or 10. Escalate to management and report the suspicious pattern to NFIU.';
        } elseif ($score >= 6) {
            $reasons[] = 'Score is 6 to 8. Escalate to management.';
        }

        return ['score' => $score, 'reasons' => array_values(array_unique($reasons))];
    }

    /**
     * Section 7 patterns: frequency, complexity, and no obvious lawful purpose.
     *
     * @param array<string, int> $structuring
     * @return array<string, array{score: int, reasons: list<string>}>
     */
    private function irregularByPhone(array $structuring): array
    {
        $pending = $this->recentPending();
        $history = $this->recentHistory();
        $dayStart = time() - 86400;
        /** @var array<string, array{score: int, reasons: list<string>}> $out */
        $out = [];

        $add = function (string|int $phone, int $score, string $reason) use (&$out): void {
            $phone = (string) $phone;
            if ($phone === '' || $score < 6) {
                return;
            }
            if (!isset($out[$phone])) {
                $out[$phone] = ['score' => 0, 'reasons' => []];
            }
            $out[$phone]['score'] = max($out[$phone]['score'], $score);
            $out[$phone]['reasons'][] = $reason;
        };

        /** @var array<string, array<string, true>> $destsByPhone */
        $destsByPhone = [];
        /** @var array<string, array<string, true>> $phonesByDest */
        $phonesByDest = [];
        /** @var array<string, array<string, list<int>>> $bankTimes */
        $bankTimes = [];

        foreach ($pending as $row) {
            $phone = (string) ($row['user_phone'] ?? '');
            $at = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $type = (string) ($row['tx_type'] ?? '');
            $activity = $this->activityType($type);
            if ($activity === 'on_ramp' || !in_array($activity, ['off_ramp', 'send'], true)) {
                continue;
            }
            $dest = $this->payoutDestination($row);
            if ($dest === null || $at < $dayStart) {
                continue;
            }
            $destsByPhone[$phone][$dest] = true;
            $phonesByDest[$dest][$phone] = true;
            if (str_starts_with($dest, 'bank:')) {
                $bankTimes[$phone][$dest][] = $at;
            }
        }

        foreach ($history as $row) {
            $phone = (string) ($row['user_phone'] ?? '');
            $at = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $own = strtolower(trim((string) ($row['aptos_address'] ?? '')));
            $other = strtolower(trim((string) ($row['counterparty'] ?? '')));
            if ((string) ($row['direction'] ?? '') === 'in') {
                continue;
            }
            if ($at < $dayStart || $other === '' || $other === $own || !$this->looksLikeAddress($other)) {
                continue;
            }
            $dest = 'wallet:' . $other;
            $destsByPhone[$phone][$dest] = true;
            $phonesByDest[$dest][$phone] = true;
        }

        foreach ($destsByPhone as $phone => $dests) {
            $count = count($dests);
            if ($count < 10) {
                continue;
            }
            $add(
                $phone,
                8,
                'One phone number paid ' . $count . ' different bank accounts or wallets in 1 day. '
                . 'Transfers are paused for 1 day, then resume automatically.'
            );
            $keys = array_keys($dests);
            sort($keys);
            $this->applyHold(
                (string) $phone,
                'many_destinations',
                $count . ' different payout destinations in 1 day.',
                sha1((string) $phone . '|' . implode(',', $keys)),
                86400
            );
        }

        foreach ($phonesByDest as $dest => $phones) {
            $count = count($phones);
            if ($count < 10) {
                continue;
            }
            $label = str_starts_with($dest, 'bank:') ? 'bank account' : 'wallet';
            $phoneKeys = array_keys($phones);
            sort($phoneKeys);
            $evidence = sha1((string) $dest . '|' . implode(',', $phoneKeys));
            foreach ($phoneKeys as $phone) {
                $add(
                    $phone,
                    8,
                    $count . ' phone numbers paid the same ' . $label . ' in 1 day. '
                    . 'Transfers are paused for 1 day, then resume automatically.'
                );
                $this->applyHold(
                    (string) $phone,
                    'many_phones_one_dest',
                    $count . ' phone numbers paid the same ' . $label . ' in 1 day.',
                    $evidence,
                    86400
                );
            }
        }

        foreach ($bankTimes as $phone => $byDest) {
            foreach ($byDest as $dest => $times) {
                $cluster = $this->rapidCluster($times);
                if ($cluster === null) {
                    continue;
                }
                $timesKey = $cluster;
                sort($timesKey);
                $add(
                    $phone,
                    6,
                    'The same bank account was paid ' . count($cluster) . ' times within 5 minutes. '
                    . 'Transfers are paused for 30 minutes, then resume automatically.'
                );
                $this->applyHold(
                    (string) $phone,
                    'rapid_same_bank',
                    'Same bank account paid ' . count($cluster) . ' times within 5 minutes.',
                    sha1((string) $phone . '|' . $dest . '|' . implode(',', $timesKey)),
                    1800
                );
            }
        }

        foreach ($this->kycGaps(array_keys($structuring)) as $phone => $open) {
            $near = $structuring[$phone] ?? 0;
            if ($near < 1) {
                continue;
            }
            if ($open) {
                $add($phone, $near >= 6 ? 8 : 6, 'KYC was left unfinished, and ' . $near . ' transaction' . ($near === 1 ? '' : 's') . ' sat just under ₦50,000 in 30 days.');
                continue;
            }
            if ($near >= 3) {
                $score = $near >= 8 ? 9 : ($near >= 6 ? 8 : 6);
                $add($phone, $score, 'No completed KYC, and ' . $near . ' transactions sat just under ₦50,000 in 30 days.');
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function payoutDestination(array $row): ?string
    {
        $meta = $this->decodeMeta($row['human_description'] ?? null);
        $account = $this->firstString($meta, ['account_number', 'sendra_user_account_number']);
        if ($account !== null) {
            $digits = preg_replace('/\D+/', '', $account) ?? '';
            if ($digits !== '') {
                return 'bank:' . $digits;
            }
        }

        if ($this->activityType((string) ($row['tx_type'] ?? '')) !== 'send') {
            return null;
        }

        $address = strtolower(trim((string) ($row['recipient_address'] ?? '')));
        if ($this->looksLikeAddress($address)) {
            return 'wallet:' . $address;
        }

        return null;
    }

    /**
     * @param list<string> $phones
     * @return array<string, bool> phone => KYC flow still open
     */
    private function kycGaps(array $phones): array
    {
        $phones = array_values(array_filter(array_unique($phones), static fn (string $phone): bool => $phone !== ''));
        if ($phones === []) {
            return [];
        }

        $cols = $this->userColumns();
        $select = ['phone_number'];
        foreach (['kyc_tier', 'kyc_verified', 'nin_last4', 'chat_context'] as $col) {
            if (isset($cols[$col])) {
                $select[] = $col;
            }
        }
        $placeholders = implode(',', array_fill(0, count($phones), '?'));
        $stmt = $this->db->prepare(
            'SELECT ' . implode(', ', $select) . " FROM users WHERE phone_number IN ({$placeholders})"
        );
        $stmt->execute($phones);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $tier = (int) ($row['kyc_tier'] ?? 0);
            $verified = !empty($row['kyc_verified']) || !empty($row['nin_last4']) || $tier >= 1;
            if ($verified) {
                continue;
            }
            $context = strtolower((string) ($row['chat_context'] ?? ''));
            $out[(string) $row['phone_number']] = str_contains($context, 'kyc');
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentPending(): array
    {
        try {
            return $this->db->query(
                "SELECT user_phone, tx_type, recipient_address, human_description, created_at
                 FROM pending_transactions
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND status IN ('pending', 'confirmed')"
            )->fetchAll();
        } catch (\Throwable $e) {
            error_log('SendraTxMonitor recent pending failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentHistory(): array
    {
        try {
            return $this->db->query(
                "SELECT user_phone, direction, aptos_address, counterparty, created_at
                 FROM transaction_history
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND status = 'confirmed'"
            )->fetchAll();
        } catch (\Throwable $e) {
            error_log('SendraTxMonitor recent history failed: ' . $e->getMessage());

            return [];
        }
    }

    private function structuringScore(int $count): int
    {
        if ($count >= 12) {
            return 10;
        }
        if ($count >= 10) {
            return 9;
        }
        if ($count >= 8) {
            return 8;
        }

        return min(7, max(0, $count));
    }

    /**
     * Off-ramps of ₦40,000–₦49,999 in the window.
     *
     * @return array{counts: array<string, int>, bursts: array<string, array{count: int, key: string}>}
     */
    private function structuringWindow(int $days): array
    {
        $days = max(1, $days);
        $sql = "
            SELECT user_phone, COUNT(*) AS c, MAX(created_at) AS latest
            FROM pending_transactions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
              AND status IN ('pending', 'confirmed')
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(human_description, '$.target_receive_ngn')) AS DECIMAL(18,2)) >= 40000
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(human_description, '$.target_receive_ngn')) AS DECIMAL(18,2)) < 50000
            GROUP BY user_phone
        ";
        try {
            $rows = $this->db->query($sql)->fetchAll();
        } catch (\Throwable $e) {
            error_log('SendraTxMonitor structuring count failed: ' . $e->getMessage());

            return ['counts' => [], 'bursts' => []];
        }

        $counts = [];
        $bursts = [];
        foreach ($rows as $row) {
            $phone = (string) $row['user_phone'];
            $count = (int) $row['c'];
            $counts[$phone] = $count;
            if ($count > 5) {
                $bursts[$phone] = [
                    'count' => $count,
                    'key' => sha1($phone . '|' . $count . '|' . (string) $row['latest']),
                ];
            }
        }

        return ['counts' => $counts, 'bursts' => $bursts];
    }

    /**
     * @param array<string, array{count: int, key: string}> $bursts
     */
    private function holdNearLimit(array $bursts): void
    {
        foreach ($bursts as $phone => $burst) {
            $count = (int) $burst['count'];
            $this->applyHold(
                (string) $phone,
                'near_limit',
                $count . ' off-ramps between ₦40,000 and ₦49,999 in 1 day.',
                (string) $burst['key'],
                86400
            );
        }
    }

    /**
     * Start a hold once per burst. An active hold is not extended, and the same
     * burst does not start again after it has already expired.
     */
    private function applyHold(string $phone, string $code, string $reason, string $evidence, int $seconds): void
    {
        $phone = trim($phone);
        $seconds = (int) $seconds;
        if ($phone === '' || $seconds < 1) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO sendra_activity_holds
                (user_phone, reason_code, reason, evidence_key, started_at, ends_at)
             VALUES
                (:phone, :code, :reason, :evidence, NOW(), DATE_ADD(NOW(), INTERVAL ' . $seconds . ' SECOND))
             ON DUPLICATE KEY UPDATE
                reason = IF(ends_at > NOW() OR evidence_key = VALUES(evidence_key), reason, VALUES(reason)),
                evidence_key = IF(ends_at > NOW() OR evidence_key = VALUES(evidence_key), evidence_key, VALUES(evidence_key)),
                started_at = IF(ends_at > NOW() OR evidence_key = VALUES(evidence_key), started_at, NOW()),
                ends_at = IF(ends_at > NOW() OR evidence_key = VALUES(evidence_key), ends_at, DATE_ADD(NOW(), INTERVAL ' . $seconds . ' SECOND))'
        );
        $stmt->execute([
            'phone' => $phone,
            'code' => $code,
            'reason' => $reason,
            'evidence' => $evidence,
        ]);
    }

    /**
     * Timestamps inside any 5-minute window, when that window has more than two payouts.
     *
     * @param list<int> $times
     * @return list<int>|null
     */
    private function rapidCluster(array $times): ?array
    {
        sort($times);
        $best = null;
        $count = count($times);
        for ($i = 0; $i < $count; $i++) {
            $cluster = [];
            for ($j = $i; $j < $count && ($times[$j] - $times[$i]) <= 300; $j++) {
                $cluster[] = $times[$j];
            }
            if (count($cluster) > 2 && ($best === null || count($cluster) > count($best))) {
                $best = $cluster;
            }
        }

        return $best;
    }

    /**
     * @return list<array{phone: string, code: string, reason: string, startedAt: string, endsAt: string}>
     */
    private function activeHolds(): array
    {
        try {
            $rows = $this->db->query(
                'SELECT user_phone, reason_code, reason, started_at, ends_at
                 FROM sendra_activity_holds
                 WHERE ends_at > NOW()
                 ORDER BY ends_at ASC
                 LIMIT 100'
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'phone' => (string) $row['user_phone'],
                'code' => (string) $row['reason_code'],
                'reason' => (string) $row['reason'],
                'startedAt' => (string) $row['started_at'],
                'endsAt' => (string) $row['ends_at'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingCandidates(): array
    {
        $sql = "
            SELECT id, user_phone, tx_type, amount, currency, recipient_address,
                   human_description, status, tx_hash, created_at
            FROM pending_transactions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
              AND status IN ('pending', 'confirmed')
            ORDER BY created_at DESC
            LIMIT 400
        ";

        return $this->db->query($sql)->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyCandidates(): array
    {
        $sql = "
            SELECT id, user_phone, direction, amount, currency, chain, status,
                   aptos_address, counterparty, tx_hash, explorer_url, created_at
            FROM transaction_history
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
              AND status = 'confirmed'
            ORDER BY created_at DESC
            LIMIT 400
        ";

        return $this->db->query($sql)->fetchAll();
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $chain
     * @return array<string, mixed>
     */
    private function details(string $activity, float $amount, string $currency, array $meta, array $chain): array
    {
        $ngn = $this->firstNumber($meta, ['target_receive_ngn', 'sendra_quoted_receive_ngn', 'pay_ngn', 'instruct_ngn']);
        if ($currency === 'NGN') {
            $ngn = max($ngn, $amount);
        }
        $crypto = $this->firstNumber($meta, ['centiiv_usdc_amount', 'panora_wallet_usdc', 'stellar_deposit_usdc', 'receive_crypto']);
        if (in_array($currency, ['USDC', 'USDT', 'USD'], true)) {
            $crypto = max($crypto, $amount);
        }
        $rate = ($crypto > 0 && $ngn > 0) ? round($ngn / $crypto, 4) : null;
        $network = $this->network(
            (string) ($chain['tx_type'] ?? ''),
            $meta,
            (string) ($chain['explorer_url'] ?? ''),
            (string) ($chain['chain'] ?? '')
        );

        $offramp = null;
        $onramp = null;
        if ($activity === 'off_ramp') {
            $offramp = [
                'account_number' => $this->firstString($meta, ['account_number', 'sendra_user_account_number']),
                'account_name' => $this->firstString($meta, ['account_name', 'sendra_user_account_name']),
                'bank_name' => $this->firstString($meta, ['bank_name', 'sendra_user_bank_name']),
                'fiat_amount' => $ngn > 0 ? $ngn : null,
                'fiat_currency' => (string) ($meta['fiat'] ?? 'NGN'),
                'crypto_amount' => $crypto > 0 ? $crypto : $amount,
                'crypto_currency' => $currency !== '' ? $currency : 'USDC',
                'conversion_rate' => $rate,
                'conversion_rate_label' => $rate !== null ? 'NGN per 1 ' . ($currency !== '' ? $currency : 'USDC') : null,
            ];
        }
        if ($activity === 'on_ramp') {
            $bank = is_array($meta['virtual_bank'] ?? null) ? $meta['virtual_bank'] : [];
            $onramp = [
                'account_number' => $this->firstString($bank, ['account_number', 'accountNumber', 'nuban']),
                'account_name' => $this->firstString($bank, ['account_name', 'accountName']),
                'bank_name' => $this->firstString($bank, ['bank_name', 'bankName']),
                'fiat_amount' => $ngn > 0 ? $ngn : null,
                'fiat_currency' => 'NGN',
                'crypto_amount' => $crypto > 0 ? $crypto : $amount,
                'crypto_currency' => $currency !== '' ? $currency : 'USDC',
                'conversion_rate' => $rate,
                'conversion_rate_label' => $rate !== null ? 'NGN per 1 ' . ($currency !== '' ? $currency : 'USDC') : null,
                'receive_display' => $this->firstString($meta, ['receive_crypto_display']),
            ];
        }

        $source = trim((string) ($chain['source_address'] ?? ''));
        $dest = trim((string) ($chain['destination_address'] ?? ''));
        $hash = trim((string) ($chain['tx_hash'] ?? ''));
        $onchain = null;
        if ($source !== '' || $dest !== '' || $hash !== '') {
            $onchain = [
                'network' => $network,
                'source_address' => $source !== '' ? $source : null,
                'destination_address' => $dest !== '' ? $dest : null,
                'amount' => $crypto > 0 ? $crypto : $amount,
                'currency' => $currency,
                'tx_hash' => $hash !== '' ? $hash : null,
                'explorer_url' => trim((string) ($chain['explorer_url'] ?? '')) ?: null,
            ];
        }

        return [
            'offramp' => $offramp,
            'onramp' => $onramp,
            'onchain' => $onchain,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string> $keys
     */
    private function firstNumber(array $meta, array $keys): float
    {
        $best = 0.0;
        foreach ($keys as $key) {
            if (isset($meta[$key]) && is_numeric($meta[$key])) {
                $best = max($best, (float) $meta[$key]);
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string> $keys
     */
    private function firstString(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function network(string $txType, array $meta, string $explorer, string $historyChain = ''): string
    {
        $stored = strtolower(trim($historyChain));
        if ($stored !== '') {
            return $stored;
        }
        $chain = strtolower(trim((string) ($meta['chain'] ?? '')));
        if ($chain !== '') {
            return $chain;
        }
        $hay = strtolower($txType . ' ' . $explorer);
        foreach (['stellar', 'arc', 'solana', 'aptos'] as $name) {
            if (str_contains($hay, $name)) {
                return $name;
            }
        }

        return 'aptos';
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{0: ?string, 1: ?string}
     */
    private function chainEndpoints(array $meta, string $recipient, string $activity): array
    {
        $recipient = trim($recipient);
        if ($activity === 'on_ramp') {
            $source = $this->firstString($meta, [
                'sendra_oneclick_deposit_address',
                'sendra_oneclick_deposit_stellar',
            ]);
            $destination = $this->firstString($meta, [
                'solana_destination',
                'sendra_user_arc',
                'sendra_user_stellar',
                'sendra_user_solana',
            ]) ?? ($recipient !== '' ? $recipient : null);

            return [$source, $destination];
        }

        $source = $this->firstString($meta, [
            'sendra_user_arc',
            'sendra_user_stellar',
            'sendra_user_solana',
        ]);
        $destination = null;
        if ($this->looksLikeAddress($recipient) && $recipient !== $source) {
            if ($source === null) {
                $source = $recipient;
            } else {
                $destination = $recipient;
            }
        }

        return [$source, $destination];
    }

    private function looksLikeAddress(string $value): bool
    {
        if ($value === '' || preg_match('/^\d{8,}$/', $value) === 1) {
            return false;
        }

        return str_starts_with($value, '0x')
            || str_starts_with($value, 'G')
            || str_starts_with($value, 'C');
    }

    private function activityType(string $txType): string
    {
        if (str_contains($txType, 'onramp')) {
            return 'on_ramp';
        }
        if (str_contains($txType, 'offramp')) {
            return 'off_ramp';
        }
        if ($txType === 'transfer') {
            return 'send';
        }

        return 'other';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function usdEquivalent(float $amount, string $currency, array $meta): float
    {
        $crypto = in_array($currency, ['USDC', 'USDT', 'USD'], true) ? $amount : 0.0;
        $crypto = max($crypto, $this->firstNumber($meta, [
            'centiiv_usdc_amount',
            'panora_wallet_usdc',
            'stellar_deposit_usdc',
            'receive_crypto',
        ]));

        $ngn = $currency === 'NGN' ? $amount : 0.0;
        $ngn = max($ngn, $this->firstNumber($meta, [
            'target_receive_ngn',
            'sendra_quoted_receive_ngn',
            'pay_ngn',
            'instruct_ngn',
        ]));

        $ngnUsd = 0.0;
        if ($ngn > 0) {
            $perUsd = ($crypto > 0) ? ($ngn / $crypto) : (float) ($_ENV['SENDRA_NGN_PER_USD'] ?? getenv('SENDRA_NGN_PER_USD') ?: 1600);
            if ($perUsd > 0) {
                $ngnUsd = $ngn / $perUsd;
            }
        }

        return max($crypto, $ngnUsd);
    }

    private function decodeMeta(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $phones
     * @return array<string, array<string, mixed>>
     */
    private function kycByPhone(array $phones): array
    {
        if ($phones === []) {
            return [];
        }
        $cols = $this->userColumns();
        $wanted = [
            'phone_number', 'kyc_full_name', 'kyc_nin_name', 'kyc_bvn_name', 'kyc_nin_phone', 'kyc_bvn_phone',
            'nin_last4', 'bvn_last4', 'kyc_tier', 'kyc_address_encrypted',
        ];
        $select = array_values(array_filter($wanted, static fn (string $col): bool => isset($cols[$col])));
        if (!in_array('phone_number', $select, true)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($phones), '?'));
        $stmt = $this->db->prepare(
            'SELECT ' . implode(', ', $select) . " FROM users WHERE phone_number IN ({$placeholders})"
        );
        $stmt->execute($phones);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['phone_number']] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $kyc
     * @return array<string, mixed>
     */
    private function present(array $row, ?array $kyc): array
    {
        $details = json_decode((string) ($row['details'] ?? ''), true);
        $reasons = json_decode((string) ($row['reasons'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'phone' => (string) $row['user_phone'],
            'activityType' => (string) $row['activity_type'],
            'status' => (string) $row['status'],
            'amount' => (string) $row['amount'],
            'currency' => (string) $row['currency'],
            'usdEquivalent' => (float) $row['usd_equivalent'],
            'riskScore' => (int) $row['risk_score'],
            'scumlReportable' => (int) $row['scuml_reportable'] === 1,
            'scumlReportedAt' => $row['scuml_reported_at'],
            'emailSentAt' => $row['email_sent_at'],
            'occurredAt' => (string) $row['occurred_at'],
            'reasons' => is_array($reasons) ? array_values($reasons) : [],
            'details' => is_array($details) ? $details : [],
            'kyc' => $this->presentKyc($kyc),
        ];
    }

    /**
     * @param array<string, mixed>|null $kyc
     * @return array<string, mixed>|null
     */
    private function presentKyc(?array $kyc): ?array
    {
        if ($kyc === null) {
            return null;
        }

        $address = null;
        $encrypted = trim((string) ($kyc['kyc_address_encrypted'] ?? ''));
        if ($encrypted !== '') {
            try {
                $raw = (new SendraKycCrypto())->decrypt($encrypted);
                $decoded = json_decode($raw, true);
                $address = is_array($decoded) ? ($decoded['full_line'] ?? $raw) : $raw;
            } catch (\Throwable $e) {
                $address = null;
            }
        }

        return [
            'tier' => isset($kyc['kyc_tier']) ? (int) $kyc['kyc_tier'] : null,
            'fullName' => $kyc['kyc_full_name'] ?? null,
            'ninName' => $kyc['kyc_nin_name'] ?? null,
            'bvnName' => $kyc['kyc_bvn_name'] ?? null,
            'ninPhone' => $kyc['kyc_nin_phone'] ?? null,
            'bvnPhone' => $kyc['kyc_bvn_phone'] ?? null,
            'ninMask' => $this->maskId($kyc['nin_last4'] ?? null),
            'bvnMask' => $this->maskId($kyc['bvn_last4'] ?? null),
            'address' => $address,
        ];
    }

    private function maskId(mixed $last): ?string
    {
        $last = trim((string) $last);
        if ($last === '') {
            return null;
        }

        return '····' . $last;
    }

    /**
     * @return array<string, true>
     */
    private function userColumns(): array
    {
        if (is_array($this->userColumnMap)) {
            return $this->userColumnMap;
        }
        $this->userColumnMap = [];
        foreach ($this->db->query('SHOW COLUMNS FROM users')->fetchAll() as $col) {
            $this->userColumnMap[(string) $col['Field']] = true;
        }

        return $this->userColumnMap;
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $row = $this->db->query(
            'SELECT
                COUNT(*) AS total,
                SUM(risk_score >= 6) AS attention,
                SUM(scuml_reportable = 1) AS scuml,
                SUM(scuml_reportable = 1 AND scuml_reported_at IS NULL) AS scuml_open
             FROM sendra_tx_monitors'
        )->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'attention' => (int) ($row['attention'] ?? 0),
            'scuml' => (int) ($row['scuml'] ?? 0),
            'scumlOpen' => (int) ($row['scuml_open'] ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $rows = $this->db->query('SELECT email FROM sendra_monitor_recipients ORDER BY email')->fetchAll();
        $emails = [];
        foreach ($rows as $row) {
            $emails[] = (string) $row['email'];
        }
        if ($emails !== []) {
            return $emails;
        }

        $env = trim((string) ($_ENV['SENDRA_RISK_ALERT_EMAILS'] ?? getenv('SENDRA_RISK_ALERT_EMAILS') ?: ''));
        if ($env === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $env)), static function (string $email): bool {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }));
    }

    private function ensureSchema(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS sendra_tx_monitors (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_table VARCHAR(32) NOT NULL,
                source_id VARCHAR(64) NOT NULL,
                user_phone VARCHAR(20) NOT NULL,
                activity_type VARCHAR(32) NOT NULL,
                status VARCHAR(20) NOT NULL,
                amount DECIMAL(20, 6) NOT NULL,
                currency VARCHAR(10) NOT NULL,
                usd_equivalent DECIMAL(20, 2) NOT NULL,
                risk_score TINYINT UNSIGNED NOT NULL,
                scuml_reportable TINYINT(1) NOT NULL DEFAULT 0,
                reasons JSON NULL,
                details JSON NULL,
                occurred_at DATETIME NOT NULL,
                email_sent_at DATETIME NULL,
                scuml_reported_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_source (source_table, source_id),
                KEY idx_score (risk_score, occurred_at),
                KEY idx_scuml (scuml_reportable, scuml_reported_at),
                KEY idx_phone (user_phone, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS sendra_activity_holds (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_phone VARCHAR(32) NOT NULL,
                reason_code VARCHAR(40) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                evidence_key CHAR(40) NOT NULL,
                started_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_phone_reason (user_phone, reason_code),
                KEY idx_ends (ends_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS sendra_monitor_recipients (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ensureUserColumn('kyc_nin_name', 'VARCHAR(200) DEFAULT NULL');
        $this->ensureUserColumn('kyc_bvn_name', 'VARCHAR(200) DEFAULT NULL');
        $this->ensureUserColumn('kyc_nin_phone', 'VARCHAR(20) DEFAULT NULL');
        $this->ensureUserColumn('kyc_bvn_phone', 'VARCHAR(20) DEFAULT NULL');
        $ready = true;
    }

    private function ensureUserColumn(string $name, string $definition): void
    {
        $cols = $this->userColumns();
        if (isset($cols[$name])) {
            return;
        }
        $this->db->exec('ALTER TABLE users ADD COLUMN `' . $name . '` ' . $definition);
        $this->userColumnMap = null;
    }
}
