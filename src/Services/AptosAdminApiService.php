<?php

namespace App\Services;

use AptosV2\Support\ChainNetwork;
use PDO;

/**
 * Read-only admin aggregates for the Sendra Aptos wallet (sendra_db).
 */
final class AptosAdminApiService
{
    private const ONRAMP_TYPES = "'onramp_oneclick_centiiv', 'onramp_daya_aptos'";

    private const OFFRAMP_TYPES = "'offramp_oneclick_centiiv', 'offramp_panora_oneclick_centiiv', 'offramp_centiiv', 'offramp_daya_aptos', 'offramp_stellar_centiiv', 'offramp_arc_centiiv'";

    private const USER_SELECT = 'Id, username, phone_number, aptos_address, stellar_address, arc_address, kyc_verified, created_at';

    private const RAMP_TYPES = self::ONRAMP_TYPES . ', ' . self::OFFRAMP_TYPES;

    private const REVENUE_RATE = 0.005;

    public function __construct(private PDO $db) {}

    public function overview(): array
    {
        $inflowUsd = $this->sumHistoryVolume('in');
        $outflowUsd = $this->sumHistoryVolume('out');
        $totalVolumeUsd = $inflowUsd + $outflowUsd;

        $onrampUsd = $this->sumRampVolume(self::ONRAMP_TYPES);
        $offrampUsd = $this->sumRampVolume(self::OFFRAMP_TYPES);
        $revenueUsd = ($onrampUsd + $offrampUsd) * self::REVENUE_RATE;

        $totalTransactions = $this->countAllTransactions();

        $vouchersClaimed = (int) $this->scalar(
            "SELECT COUNT(*) FROM vouchers WHERE status = 'claimed'"
        );
        $vouchersTotal = (int) $this->scalar('SELECT COUNT(*) FROM vouchers');

        return [
            'stats' => [
                'totalVolumeUsd' => round($totalVolumeUsd, 2),
                'inflowUsd' => round($inflowUsd, 2),
                'outflowUsd' => round($outflowUsd, 2),
                'inflowSuccessRate' => $this->rampSuccessRate(self::ONRAMP_TYPES),
                'outflowSuccessRate' => $this->rampSuccessRate(self::OFFRAMP_TYPES),
                'revenueUsd' => round($revenueUsd, 2),
                'totalTransactions' => $totalTransactions,
                'vouchersClaimed' => $vouchersClaimed,
                'vouchersTotal' => $vouchersTotal,
                'voucherSuccessRate' => $this->voucherSuccessRate(),
            ],
            'recentActivities' => $this->recentActivities(12),
        ];
    }

    public function customers(
        int $limit = 100,
        int $offset = 0,
        ?string $search = null,
        ?string $kycFilter = null,
        string $sort = 'last_added',
    ): array {
        $where = ' WHERE 1=1 ';
        $params = [];

        if ($search !== null && $search !== '') {
            $where .= ' AND (phone_number LIKE :q OR username LIKE :q OR aptos_address LIKE :q) ';
            $params['q'] = '%' . $search . '%';
        }

        $kycSql = $this->kycTierSql();
        if ($kycFilter !== null && $kycFilter !== '' && $kycFilter !== 'all') {
            $where .= " AND ({$kycSql}) = :kyc ";
            $params['kyc'] = $kycFilter;
        }

        $totalCustomers = (int) $this->scalar('SELECT COUNT(*) FROM users' . $where, $params);
        $activeCustomers = (int) $this->scalar(
            "SELECT COUNT(*) FROM users
             WHERE pin_hash IS NOT NULL AND pin_hash != ''
               AND aptos_address IS NOT NULL AND aptos_address != ''"
        );
        $kycVerified = (int) $this->scalar(
            "SELECT COUNT(*) FROM users
             WHERE ({$kycSql}) IN ('tier1', 'tier2', 'tier3')"
        );

        $orderBy = match ($sort) {
            'name' => 'username ASC',
            default => 'created_at DESC',
        };

        $sql = "
            SELECT
                Id AS id,
                username,
                phone_number,
                aptos_address,
                pin_hash,
                kyc_verified,
                smiled_verified,
                created_at,
                ({$kycSql}) AS kyc_tier
            FROM users
            {$where}
            ORDER BY {$orderBy}
            LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = $this->fetchAll($sql, $params);

        return [
            'stats' => [
                'totalCustomers' => $totalCustomers,
                'activeCustomers' => $activeCustomers,
                'kycVerified' => $kycVerified,
                'kycTotal' => $totalCustomers,
            ],
            'customers' => array_map(fn (array $r) => [
                'id' => (int) $r['id'],
                'name' => $this->displayName($r),
                'number' => $this->formatPhone((string) ($r['phone_number'] ?? '')),
                'wallet' => (string) ($r['aptos_address'] ?? ''),
                'walletShort' => $this->shortAddress((string) ($r['aptos_address'] ?? '')),
                'kycStatus' => $this->formatKycTier((string) ($r['kyc_tier'] ?? 'inactive')),
            ], $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerKyc(int $id): array
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $columns = [];
        foreach ($this->fetchAll('SHOW COLUMNS FROM users') as $col) {
            $columns[(string) ($col['Field'] ?? '')] = true;
        }
        $wanted = [
            'Id', 'phone_number', 'username', 'kyc_tier', 'kyc_verified', 'kyc_full_name',
            'kyc_nin_name', 'kyc_bvn_name', 'kyc_nin_phone', 'kyc_bvn_phone',
            'nin_last4', 'bvn_last4', 'liveness_verified', 'address_verified',
            'kyc_address_encrypted', 'kyc_verified_at', 'smiled_verified',
            'kyc_source_of_wealth', 'kyc_sow_proof_file',
        ];
        $select = array_values(array_filter($wanted, static fn (string $col): bool => isset($columns[$col])));
        if (!in_array('Id', $select, true)) {
            throw new \InvalidArgumentException('Customer not found');
        }

        $rows = $this->fetchAll(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE Id = :id LIMIT 1',
            ['id' => $id]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Customer not found');
        }

        $level = $this->completedKycLevel($row);
        $tiers = [];
        if ($level >= 1) {
            $sourceKey = (string) ($row['kyc_source_of_wealth'] ?? '');
            $sources = SendraKycVerificationService::sourceOfWealthOptions();
            $tier1Fields = [
                ['label' => 'Source of wealth', 'value' => $sources[$sourceKey] ?? ($sourceKey !== '' ? $sourceKey : null)],
                ['label' => 'Name on NIN', 'value' => $this->kycValue($row, 'kyc_nin_name', 'kyc_full_name')],
                ['label' => 'Phone on NIN', 'value' => $this->kycValue($row, 'kyc_nin_phone', 'phone_number')],
                ['label' => 'NIN', 'value' => $this->maskLast4($row['nin_last4'] ?? null)],
            ];
            if (!empty($row['kyc_sow_proof_file'])) {
                $tier1Fields[] = ['label' => 'Proof of source of wealth', 'value' => 'Uploaded', 'proof' => true];
            }
            $tiers[] = [
                'tier' => 1,
                'label' => 'Tier 1',
                'fields' => $tier1Fields,
            ];
        }
        if ($level >= 2) {
            $tiers[] = [
                'tier' => 2,
                'label' => 'Tier 2',
                'fields' => [
                    ['label' => 'Name on BVN', 'value' => $this->kycValue($row, 'kyc_bvn_name', 'kyc_full_name')],
                    ['label' => 'Phone on BVN', 'value' => $this->kycValue($row, 'kyc_bvn_phone', 'phone_number')],
                    ['label' => 'BVN', 'value' => $this->maskLast4($row['bvn_last4'] ?? null)],
                    ['label' => 'Liveness', 'value' => !empty($row['liveness_verified']) ? 'Passed' : '—'],
                ],
            ];
        }
        if ($level >= 3) {
            $address = $this->decryptKycAddress((string) ($row['kyc_address_encrypted'] ?? ''));
            $tiers[] = [
                'tier' => 3,
                'label' => 'Tier 3',
                'fields' => [
                    ['label' => 'Address', 'value' => $address['full_line'] ?? null],
                    ['label' => 'House number', 'value' => $address['house_no'] ?? null],
                    ['label' => 'Street', 'value' => $address['street'] ?? null],
                    ['label' => 'LGA', 'value' => $address['lga'] ?? null],
                    ['label' => 'State', 'value' => $address['state'] ?? null],
                    ['label' => 'Proof', 'value' => $address['proof_type'] ?? null],
                ],
            ];
        }

        return [
            'id' => $id,
            'name' => $this->displayName($row),
            'phone' => $this->formatPhone((string) ($row['phone_number'] ?? '')),
            'kycStatus' => $this->formatKycTier($level === 3 ? 'tier3' : ($level === 2 ? 'tier2' : ($level === 1 ? 'tier1' : 'inactive'))),
            'verifiedAt' => $row['kyc_verified_at'] ?? null,
            'tiers' => $tiers,
        ];
    }

    public function streamSourceOfWealthProof(int $id): never
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('id is required');
        }

        $rows = $this->fetchAll(
            'SELECT kyc_sow_proof_file FROM users WHERE Id = :id LIMIT 1',
            ['id' => $id]
        );
        $name = basename((string) ($rows[0]['kyc_sow_proof_file'] ?? ''));
        $dir = realpath(dirname(__DIR__, 2) . '/var/kyc-sow');
        $path = ($dir !== false && $name !== '') ? realpath($dir . '/' . $name) : false;
        if ($dir === false || $path === false || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new \InvalidArgumentException('Proof document not found');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="source-of-wealth"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function completedKycLevel(array $row): int
    {
        $tier = (int) ($row['kyc_tier'] ?? 0);
        if ($tier >= 3 || !empty($row['address_verified']) || !empty($row['smiled_verified'])) {
            return 3;
        }
        if ($tier >= 2 || !empty($row['liveness_verified'])) {
            return 2;
        }
        if ($tier >= 1 || !empty($row['kyc_verified']) || !empty($row['nin_last4']) || !empty($row['kyc_full_name'])) {
            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function kycValue(array $row, string $primary, string $fallback): ?string
    {
        $value = trim((string) ($row[$primary] ?? ''));
        if ($value === '') {
            $value = trim((string) ($row[$fallback] ?? ''));
        }

        return $value !== '' ? $value : null;
    }

    private function maskLast4(mixed $last): ?string
    {
        $last = trim((string) $last);
        if ($last === '') {
            return null;
        }

        return '····' . $last;
    }

    /**
     * @return array<string, string>
     */
    private function decryptKycAddress(string $encrypted): array
    {
        if ($encrypted === '') {
            return [];
        }
        try {
            $raw = (new SendraKycCrypto())->decrypt($encrypted);
        } catch (\Throwable $e) {
            return ['full_line' => 'Address is on file'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['full_line' => $raw];
        }
        $out = [];
        foreach (['full_line', 'house_no', 'street', 'lga', 'state', 'proof_type'] as $key) {
            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function transactions(
        int $limit = 50,
        int $offset = 0,
        ?string $search = null,
        ?string $typeFilter = null,
        ?string $statusFilter = null,
    ): array {
        $params = [];
        $searchClause = '';
        if ($search !== null && $search !== '') {
            $searchClause = ' AND (
                user_phone LIKE :q OR tx_hash LIKE :q OR counterparty LIKE :q
                OR recipient_address LIKE :q
            ) ';
            $params['q'] = '%' . $search . '%';
        }

        $typeClause = '';
        if ($typeFilter !== null && $typeFilter !== '' && $typeFilter !== 'all') {
            $typeClause = ' AND activity_type = :type ';
            $params['type'] = $typeFilter;
        }

        $statusClause = '';
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $statusClause = ' AND status = :status ';
            $params['status'] = $statusFilter;
        }

        $union = $this->transactionsUnionSql();

        $countSql = "
            SELECT COUNT(*) FROM (
                {$union}
            ) AS all_tx
            WHERE 1=1 {$searchClause} {$typeClause} {$statusClause}
        ";
        $total = (int) $this->scalar($countSql, $params);

        $inCount = (int) $this->scalar(
            "SELECT COUNT(*) FROM ({$union}) AS all_tx
             WHERE activity_type IN ('on_ramp', 'deposit') AND status = 'confirmed'"
        );
        $outCount = (int) $this->scalar(
            "SELECT COUNT(*) FROM ({$union}) AS all_tx
             WHERE activity_type IN ('off_ramp', 'withdrawal', 'send') AND status = 'confirmed'"
        );

        $sql = "
            SELECT * FROM (
                {$union}
            ) AS all_tx
            WHERE 1=1 {$searchClause} {$typeClause} {$statusClause}
            ORDER BY occurred_at DESC
            LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = $this->fetchAll($sql, $params);

        return [
            'stats' => [
                'totalTransactions' => $total,
                'inCount' => $inCount,
                'outCount' => $outCount,
                'inflowSuccessRate' => $this->rampSuccessRate(self::ONRAMP_TYPES),
                'outflowSuccessRate' => $this->rampSuccessRate(self::OFFRAMP_TYPES),
            ],
            'pagination' => [
                'page' => (int) floor($offset / max($limit, 1)) + 1,
                'pageSize' => $limit,
                'total' => $total,
                'totalPages' => (int) max(1, ceil($total / max($limit, 1))),
            ],
            'transactions' => array_map(fn (array $r) => [
                'id' => $this->shortTxId((string) ($r['tx_hash'] ?? $r['row_id'] ?? '')),
                'timestamp' => $this->formatTimestamp($r['occurred_at']),
                'number' => $this->formatPhone((string) ($r['user_phone'] ?? '')),
                'type' => $this->formatTxType((string) ($r['activity_type'] ?? '')),
                'typeKey' => (string) ($r['activity_type'] ?? ''),
                'amount' => number_format((float) ($r['amount'] ?? 0), 3, '.', ''),
                'currency' => strtoupper((string) ($r['currency'] ?? '')),
                'status' => $this->formatTxStatus((string) ($r['status'] ?? '')),
                'from' => $this->shortAddress((string) ($r['from_address'] ?? '')),
                'to' => $this->shortAddress((string) ($r['to_address'] ?? '')),
                'tx' => !empty($r['explorer_url']) ? (string) $r['explorer_url'] : null,
            ], $rows),
        ];
    }

    public function analytics(): array
    {
        $totalUsers = (int) $this->scalar('SELECT COUNT(*) FROM users');
        $newUsersToday = (int) $this->scalar(
            'SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()'
        );
        $newUsersWeekAgo = (int) $this->scalar(
            'SELECT COUNT(*) FROM users WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 7 DAY)'
        );
        $withPin = (int) $this->scalar(
            "SELECT COUNT(*) FROM users WHERE pin_hash IS NOT NULL AND pin_hash != ''"
        );
        $activationRate = $totalUsers > 0 ? round(($withPin / $totalUsers) * 100, 1) : 0.0;

        $dau = (int) $this->scalar(
            "SELECT COUNT(DISTINCT user_phone) FROM transaction_history
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $dauYesterday = (int) $this->scalar(
            "SELECT COUNT(DISTINCT user_phone) FROM transaction_history
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
               AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        $usageTrends = $this->usageTrends(30);
        $activityDistribution = $this->activityDistribution();

        return [
            'stats' => [
                'newUsersToday' => $newUsersToday,
                'newUsersTrendPct' => $this->percentChange($newUsersToday, $newUsersWeekAgo),
                'activationRate' => $activationRate,
                'activationTrendPct' => 0.0,
                'dailyActiveUsers' => $dau,
                'dauTrendPct' => $this->percentChange($dau, $dauYesterday),
            ],
            'usageTrends' => $usageTrends,
            'activityDistribution' => $activityDistribution,
        ];
    }

    public function supportTickets(int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        return [
            'stats' => [
                'open' => 0,
                'resolved' => 0,
                'total' => 0,
            ],
            'pagination' => [
                'page' => 1,
                'pageSize' => $limit,
                'total' => 0,
                'totalPages' => 1,
            ],
            'tickets' => [],
        ];
    }

    private function transactionsUnionSql(): string
    {
        return "
            SELECT
                pt.id AS row_id,
                pt.user_phone,
                pt.amount,
                pt.currency,
                pt.status,
                pt.tx_hash,
                NULL AS explorer_url,
                pt.recipient_address AS counterparty,
                pt.created_at AS occurred_at,
                CASE
                    WHEN pt.tx_type IN (" . self::ONRAMP_TYPES . ") THEN 'on_ramp'
                    WHEN pt.tx_type IN (" . self::OFFRAMP_TYPES . ") THEN 'off_ramp'
                    WHEN pt.tx_type = 'transfer' THEN 'send'
                    WHEN pt.tx_type = 'panora_wallet_swap' THEN 'swap'
                    ELSE 'other'
                END AS activity_type,
                pt.user_phone AS from_address,
                pt.recipient_address AS to_address
            FROM pending_transactions pt

            UNION ALL

            SELECT
                th.id AS row_id,
                th.user_phone,
                th.amount,
                th.currency,
                th.status,
                th.tx_hash,
                th.explorer_url,
                th.counterparty,
                th.created_at AS occurred_at,
                CASE
                    WHEN th.direction = 'in' THEN 'deposit'
                    ELSE 'withdrawal'
                END AS activity_type,
                CASE WHEN th.direction = 'out' THEN th.aptos_address ELSE th.counterparty END AS from_address,
                CASE WHEN th.direction = 'in' THEN th.aptos_address ELSE th.counterparty END AS to_address
            FROM transaction_history th
        ";
    }

    private function kycTierSql(): string
    {
        return "
            CASE
                WHEN kyc_tier >= 3 OR address_verified = 1 OR smiled_verified = 1 THEN 'tier3'
                WHEN kyc_tier >= 2 OR (liveness_verified = 1 AND kyc_verified = 1) THEN 'tier2'
                WHEN kyc_tier >= 1 OR kyc_verified = 1 THEN 'tier1'
                WHEN pin_hash IS NOT NULL AND pin_hash != '' AND aptos_address IS NOT NULL AND aptos_address != '' THEN 'tier0'
                ELSE 'inactive'
            END
        ";
    }

    private function formatKycTier(string $tier): string
    {
        return match ($tier) {
            'tier0' => 'Tier 0',
            'tier1' => 'Tier 1',
            'tier2' => 'Tier 2',
            'tier3' => 'Tier 3',
            default => 'Inactive',
        };
    }

    private function formatTxType(string $key): string
    {
        return match ($key) {
            'on_ramp' => 'On-Ramp',
            'off_ramp' => 'Off-ramp',
            'deposit' => 'Deposit',
            'withdrawal' => 'Withdrawal',
            'send' => 'Send',
            'swap' => 'Swap',
            default => 'Other',
        };
    }

    private function formatTxStatus(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Success',
            'pending' => 'Pending',
            'failed', 'cancelled', 'expired' => 'Failed',
            default => ucfirst($status),
        };
    }

    /** @return list<array{day: int, activeUsers: int}> */
    private function usageTrends(int $days): array
    {
        $rows = $this->fetchAll("
            SELECT
                DATE(created_at) AS activity_date,
                COUNT(DISTINCT user_phone) AS active_users
            FROM transaction_history
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(created_at)
            ORDER BY activity_date ASC
        ", ['days' => $days - 1]);

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['activity_date']] = (int) $row['active_users'];
        }

        $trends = [];
        for ($d = $days; $d >= 1; $d--) {
            $date = date('Y-m-d', strtotime('-' . ($d - 1) . ' days'));
            $trends[] = [
                'day' => $days - $d + 1,
                'activeUsers' => $byDate[$date] ?? 0,
            ];
        }

        return $trends;
    }

    /** @return list<array{label: string, count: int}> */
    private function activityDistribution(): array
    {
        $union = $this->transactionsUnionSql();
        $rows = $this->fetchAll("
            SELECT activity_type, COUNT(*) AS cnt
            FROM ({$union}) AS all_tx
            WHERE status = 'confirmed'
            GROUP BY activity_type
            ORDER BY cnt DESC
        ");

        return array_map(fn (array $r) => [
            'label' => $this->formatTxType((string) $r['activity_type']),
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    private function countAllTransactions(): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM (' . $this->transactionsUnionSql() . ') AS all_tx'
        );
    }

    private function sumHistoryVolume(string $direction): float
    {
        return (float) $this->scalar(
            "SELECT COALESCE(SUM(amount), 0)
             FROM transaction_history
             WHERE status = 'confirmed' AND direction = :dir",
            ['dir' => $direction]
        );
    }

    private function sumRampVolume(string $txTypes): float
    {
        return (float) $this->scalar(
            "SELECT COALESCE(SUM(amount), 0)
             FROM pending_transactions
             WHERE status = 'confirmed' AND tx_type IN ({$txTypes})"
        );
    }

    private function rampSuccessRate(string $txTypes): float
    {
        $completed = (int) $this->scalar(
            "SELECT COUNT(*) FROM pending_transactions
             WHERE tx_type IN ({$txTypes}) AND status = 'confirmed'"
        );
        $failed = (int) $this->scalar(
            "SELECT COUNT(*) FROM pending_transactions
             WHERE tx_type IN ({$txTypes}) AND status IN ('failed', 'cancelled', 'expired')"
        );
        $total = $completed + $failed;

        return $total > 0 ? round(($completed / $total) * 100, 1) : 100.0;
    }

    private function voucherSuccessRate(): float
    {
        $claimed = (int) $this->scalar("SELECT COUNT(*) FROM vouchers WHERE status = 'claimed'");
        $expired = (int) $this->scalar(
            "SELECT COUNT(*) FROM vouchers
             WHERE status = 'pending' AND expires_at < NOW()"
        );
        $total = $claimed + $expired;

        return $total > 0 ? round(($claimed / $total) * 100, 1) : 100.0;
    }

    private function percentChange(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /** @return list<array<string, string>> */
    private function recentActivities(int $limit): array
    {
        $sql = "
            SELECT kind, title, meta, occurred_at FROM (
                SELECT
                    'onramp' AS kind,
                    CONCAT('On-ramp - User: ', user_phone) AS title,
                    CONCAT(
                        'Amount: ', amount, ' ', currency,
                        CASE WHEN tx_hash IS NOT NULL AND tx_hash != '' THEN CONCAT(' • Tx: ', LEFT(tx_hash, 10), '…') ELSE '' END
                    ) AS meta,
                    created_at AS occurred_at
                FROM pending_transactions
                WHERE tx_type IN (" . self::ONRAMP_TYPES . ")
                  AND status IN ('confirmed', 'pending')

                UNION ALL

                SELECT
                    'offramp',
                    CONCAT('Off ramp - User: ', user_phone),
                    CONCAT(
                        'Amount: ', amount, ' ', currency,
                        CASE WHEN tx_hash IS NOT NULL AND tx_hash != '' THEN CONCAT(' • Tx: ', LEFT(tx_hash, 10), '…') ELSE '' END
                    ),
                    created_at
                FROM pending_transactions
                WHERE tx_type IN (" . self::OFFRAMP_TYPES . ")
                  AND status IN ('confirmed', 'pending')

                UNION ALL

                SELECT
                    'swap',
                    CONCAT('Swap - User: ', user_phone),
                    CONCAT('Amount: ', amount, ' ', currency),
                    created_at
                FROM pending_transactions
                WHERE tx_type = 'panora_wallet_swap'
                  AND status IN ('confirmed', 'pending')

                UNION ALL

                SELECT
                    CASE WHEN direction = 'in' THEN 'receive' ELSE 'send' END,
                    CONCAT(
                        CASE WHEN direction = 'in' THEN 'Receive' ELSE 'Send' END,
                        ' - User: ', user_phone
                    ),
                    CONCAT(
                        'From: ', COALESCE(LEFT(counterparty, 10), '—'), '…',
                        ' | Amount: ', amount, ' ', currency,
                        CASE WHEN tx_hash IS NOT NULL AND tx_hash != '' THEN CONCAT(' | Tx hash: ', LEFT(tx_hash, 10), '…') ELSE '' END
                    ),
                    created_at
                FROM transaction_history
                WHERE status = 'confirmed'
            ) AS activity
            ORDER BY occurred_at DESC
            LIMIT " . (int) $limit;

        $rows = $this->fetchAll($sql);

        return array_map(fn (array $r) => [
            'kind' => (string) $r['kind'],
            'title' => (string) $r['title'],
            'meta' => (string) $r['meta'],
            'time' => $this->formatTime($r['occurred_at']),
        ], $rows);
    }

    private function displayName(array $row): string
    {
        $username = trim((string) ($row['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        return $this->formatPhone((string) ($row['phone_number'] ?? ''));
    }

    private function formatPhone(string $phone): string
    {
        if ($phone === '') {
            return '—';
        }
        if (str_starts_with($phone, '234') && strlen($phone) === 13) {
            return '0' . substr($phone, 3);
        }

        return $phone;
    }

    private function shortAddress(string $addr): string
    {
        if ($addr === '') {
            return '—';
        }
        if (strlen($addr) <= 14) {
            return $addr;
        }

        return substr($addr, 0, 5) . '...' . substr($addr, -3);
    }

    private function shortTxId(string $hash): string
    {
        if ($hash === '') {
            return '—';
        }

        return substr($hash, 0, 4) . '...';
    }

    private function formatTimestamp(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '—';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }

        return date('d/m/Y g:i:sa', $ts);
    }

    private function formatTime(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '—';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }

        return date('g:i:s A', $ts);
    }

    public function inspectTransaction(string $query): array
    {
        $q = trim($query);
        // 1. Initial User & Tx pre-resolution
        $phoneNorm = preg_replace('/\D+/', '', $q);
        $userRow = null;
        $targetPhone = null;
        $targetCounterparty = null;
        $resolvedChain = null;
        $matchedHistoryRow = null;
        $looksLikeHash = $this->looksLikeTxHash($q);

        // Match any-chain tx hash or history row id first (do not assume 0x = Aptos).
        if ($looksLikeHash || strlen($q) >= 30) {
            $txHist = $this->fetchAll(
                'SELECT * FROM transaction_history WHERE tx_hash = :q OR id = :q ORDER BY created_at DESC LIMIT 1',
                ['q' => $q]
            )[0] ?? null;
            if ($txHist) {
                $matchedHistoryRow = $txHist;
                $targetPhone = $txHist['user_phone'] ?? null;
                $targetCounterparty = $txHist['counterparty'] ?? null;
                $resolvedChain = ChainNetwork::resolve(
                    (string) ($txHist['chain'] ?? ''),
                    (string) ($txHist['explorer_url'] ?? '')
                );
            }
        }

        if (!$targetPhone && $phoneNorm !== '') {
            $targetPhone = $phoneNorm;
        }

        $userCols = self::USER_SELECT . ', (' . $this->kycTierSql() . ') AS kyc_tier';

        if ($targetPhone) {
            $userRow = $this->fetchAll(
                "SELECT {$userCols}
                 FROM users WHERE phone_number = :p OR phone_number LIKE :lp LIMIT 1",
                ['p' => $targetPhone, 'lp' => '%' . $targetPhone . '%']
            )[0] ?? null;
        }
        if (!$userRow && $looksLikeHash && strlen($q) <= 70) {
            $userRow = $this->fetchAll(
                "SELECT {$userCols}
                 FROM users
                 WHERE aptos_address = :addr OR stellar_address = :addr OR arc_address = :addr
                 LIMIT 1",
                ['addr' => $q]
            )[0] ?? null;
        }

        $userPhone = $userRow['phone_number'] ?? $targetPhone;

        // 2. Offramp Outcome DB record
        $specificHashLookup = $looksLikeHash && strlen($q) >= 40;
        $outcomeRow = null;
        if ($specificHashLookup) {
            $outcomeRow = $this->fetchAll(
                'SELECT * FROM sendra_offramp_outcomes
                 WHERE aptos_tx_hash = :q OR oneclick_deposit_address = :q
                 ORDER BY id DESC LIMIT 1',
                ['q' => $q]
            )[0] ?? null;
            if ($outcomeRow && $resolvedChain === null) {
                $resolvedChain = $this->chainFromOfframpPath((string) ($outcomeRow['offramp_path'] ?? ''));
            }
        }
        if (!$outcomeRow && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $q)) {
            $outcomeRow = $this->fetchAll(
                'SELECT * FROM sendra_offramp_outcomes WHERE centiiv_order_id = :q ORDER BY id DESC LIMIT 1',
                ['q' => $q]
            )[0] ?? null;
        }
        // Phone / generic search: latest outcome. Skip this for a specific tx hash so
        // row-click does not attach an unrelated newer stellar_native/oneclick outcome.
        if (!$outcomeRow && !$specificHashLookup && $userPhone) {
            $outcomeRow = $this->fetchAll(
                'SELECT * FROM sendra_offramp_outcomes WHERE user_phone = :p OR centiiv_order_id = :q ORDER BY id DESC LIMIT 1',
                ['p' => $userPhone, 'q' => $q]
            )[0] ?? null;
        } elseif (!$outcomeRow && !$specificHashLookup) {
            $outcomeRow = $this->fetchAll(
                'SELECT * FROM sendra_offramp_outcomes WHERE centiiv_order_id = :q ORDER BY id DESC LIMIT 1',
                ['q' => $q]
            )[0] ?? null;
        }

        if ($resolvedChain === null && $outcomeRow) {
            $resolvedChain = $this->chainFromOfframpPath((string) ($outcomeRow['offramp_path'] ?? ''));
        }

        // 3. Pending order file
        $pendingOrderData = null;
        if ($userPhone) {
            $filePath = dirname(__DIR__, 2) . '/var/sendra_centiiv_pending_orders/' . $userPhone . '.json';
            if (file_exists($filePath)) {
                $rawJson = @file_get_contents($filePath);
                if ($rawJson) {
                    $pendingOrderData = json_decode($rawJson, true);
                }
            }
        }

        // 4. Resolve Centiiv Order ID
        $centiivOrderId = null;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $q)) {
            $centiivOrderId = $q;
        } elseif (!empty($outcomeRow['centiiv_order_id'])) {
            $centiivOrderId = (string) $outcomeRow['centiiv_order_id'];
        } elseif (!empty($pendingOrderData['order']['id'])) {
            $centiivOrderId = (string) $pendingOrderData['order']['id'];
        }

        // Fetch Centiiv Status if order ID available
        $centiivData = null;
        if ($centiivOrderId) {
            try {
                $centiivService = new SendraCentiivRampService();
                if ($centiivService->isConfigured()) {
                    $cRes = $centiivService->getRequest($centiivOrderId);
                    if ($cRes['success'] && !empty($cRes['data'])) {
                        $centiivData = $cRes['data'];
                        $bCode = (string) ($centiivData['beneficiary']['details']['bankCode'] ?? '');
                        if ($bCode !== '') {
                            $centiivData['beneficiary']['details']['bankName'] =
                                SendraCentiivRampService::fallbackNipBankDisplayName($bCode) ?? ('Bank code ' . $bCode);
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('AptosAdminApiService inspect Centiiv error: ' . $e->getMessage());
            }
        }

        // 5. Resolve 1Click / native pending context (skip Aptos-only 1Click probes for Arc/Stellar)
        $allowOneClickProbe = $resolvedChain === null
            || $resolvedChain === ChainNetwork::APTOS
            || in_array((string) ($outcomeRow['offramp_path'] ?? ''), ['oneclick', 'panora_oneclick'], true);

        $pendingOneClick = null;
        $pendingNative = null;
        if ($allowOneClickProbe) {
            $pendingOneClick = $this->findOneClickPendingContext(
                $q,
                $userPhone,
                $centiivOrderId,
                is_string($targetCounterparty) ? $targetCounterparty : null
            );
        }
        if ($resolvedChain === ChainNetwork::STELLAR || $resolvedChain === ChainNetwork::ARC || !$allowOneClickProbe) {
            $pendingNative = $this->findNativeOfframpPendingContext(
                $q,
                $userPhone,
                $centiivOrderId,
                $resolvedChain
            );
        }

        $oneClickAddress = null;
        if ($allowOneClickProbe) {
            if (!empty($outcomeRow['oneclick_deposit_address'])) {
                $oneClickAddress = (string) $outcomeRow['oneclick_deposit_address'];
            } elseif (!empty($pendingOneClick['deposit_address'])) {
                $oneClickAddress = (string) $pendingOneClick['deposit_address'];
            } elseif ($targetCounterparty && str_starts_with(strtolower((string) $targetCounterparty), '0x') && strlen((string) $targetCounterparty) >= 42) {
                $oneClickAddress = (string) $targetCounterparty;
            }
        }

        $oneClickData = null;
        $oneClickService = new SendraOneClickService();

        // Aptos deposit addresses and tx hashes are both 0x + 64 hex — try status first (Aptos path only).
        if ($allowOneClickProbe && !$oneClickAddress && str_starts_with(strtolower($q), '0x') && strlen($q) >= 42 && $oneClickService->isConfigured()) {
            $ocProbe = $oneClickService->getStatus($q);
            if (!empty($ocProbe['success']) && !empty($ocProbe['data']['status'])) {
                $oneClickAddress = $q;
                $oneClickData = $ocProbe['data'];
                $resolvedChain = $resolvedChain ?? ChainNetwork::APTOS;
            }
        }

        if ($allowOneClickProbe && !$oneClickData && $oneClickAddress && $oneClickService->isConfigured()) {
            $ocRes = $oneClickService->getStatus($oneClickAddress);
            if ($ocRes['success'] && !empty($ocRes['data'])) {
                $oneClickData = $ocRes['data'];
            }
        }

        // Pending row may only have correlation id; still prefer deposit address for /v0/status.
        if ($allowOneClickProbe && !$oneClickData && !empty($pendingOneClick['deposit_address']) && $oneClickService->isConfigured()) {
            $oneClickAddress = (string) $pendingOneClick['deposit_address'];
            $ocRes = $oneClickService->getStatus($oneClickAddress);
            if ($ocRes['success'] && !empty($ocRes['data'])) {
                $oneClickData = $ocRes['data'];
            }
        }

        // If $q is an Aptos transaction hash, recover deposit from PM2 logs as last resort.
        if ($allowOneClickProbe && !$oneClickData && str_starts_with(strtolower($q), '0x') && strlen($q) >= 60 && $oneClickService->isConfigured()) {
            $logPath = '/root/.pm2/logs/aptos-service-out.log';
            if (file_exists($logPath)) {
                $cmd = 'grep -B 20 ' . escapeshellarg($q) . ' ' . escapeshellarg($logPath) . ' | tail -n 30';
                $logLines = shell_exec($cmd);
                if ($logLines && preg_match_all('/0x[a-f0-9]{64}/i', $logLines, $matches)) {
                    foreach (array_reverse($matches[0]) as $foundHex) {
                        if (strtolower($foundHex) !== strtolower($q) && !str_starts_with(strtolower($foundHex), '0xbae2')) {
                            $ocTest = $oneClickService->getStatus($foundHex);
                            if (!empty($ocTest['success']) && !empty($ocTest['data']['status'])) {
                                $oneClickAddress = $foundHex;
                                $oneClickData = $ocTest['data'];
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Fallback: search PM2 log for user Aptos address if still not found
        if ($allowOneClickProbe && !$oneClickData && $userRow && !empty($userRow['aptos_address']) && $oneClickService->isConfigured()) {
            $userAptosAddr = $userRow['aptos_address'];
            $logPath = '/root/.pm2/logs/aptos-service-out.log';
            if (file_exists($logPath)) {
                $cmd = 'grep -B 20 ' . escapeshellarg($userAptosAddr) . ' ' . escapeshellarg($logPath) . ' | tail -n 40';
                $logLines = shell_exec($cmd);
                if ($logLines && preg_match_all('/0x[a-f0-9]{64}/i', $logLines, $matches)) {
                    foreach (array_reverse($matches[0]) as $foundHex) {
                        if (strtolower($foundHex) !== strtolower($userAptosAddr) && !str_starts_with(strtolower($foundHex), '0xbae2')) {
                            $ocTest = $oneClickService->getStatus($foundHex);
                            if (!empty($ocTest['success']) && !empty($ocTest['data']['status'])) {
                                $oneClickAddress = $foundHex;
                                $oneClickData = $ocTest['data'];
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Prefer pending Centiiv order when the selected tx is a 1Click or native offramp.
        $pendingCentiivId = !empty($pendingOneClick['centiiv_order_id'])
            ? (string) $pendingOneClick['centiiv_order_id']
            : (!empty($pendingNative['centiiv_order_id']) ? (string) $pendingNative['centiiv_order_id'] : '');
        if ($pendingCentiivId !== '' && ($centiivOrderId === null || $specificHashLookup)) {
            $centiivOrderId = $pendingCentiivId;
            $centiivData = null;
            try {
                $centiivService = new SendraCentiivRampService();
                if ($centiivService->isConfigured()) {
                    $cRes = $centiivService->getRequest($centiivOrderId);
                    if ($cRes['success'] && !empty($cRes['data'])) {
                        $centiivData = $cRes['data'];
                        $bCode = (string) ($centiivData['beneficiary']['details']['bankCode'] ?? '');
                        if ($bCode !== '') {
                            $centiivData['beneficiary']['details']['bankName'] =
                                SendraCentiivRampService::fallbackNipBankDisplayName($bCode) ?? ('Bank code ' . $bCode);
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('AptosAdminApiService inspect Centiiv (pending) error: ' . $e->getMessage());
            }
            $matchedOutcome = $this->fetchAll(
                'SELECT * FROM sendra_offramp_outcomes WHERE centiiv_order_id = :q ORDER BY id DESC LIMIT 1',
                ['q' => $centiivOrderId]
            )[0] ?? null;
            if ($matchedOutcome) {
                $outcomeRow = $matchedOutcome;
            }
        } elseif (!$outcomeRow && $centiivOrderId) {
            $outcomeRow = $this->fetchAll(
                'SELECT * FROM sendra_offramp_outcomes WHERE centiiv_order_id = :q ORDER BY id DESC LIMIT 1',
                ['q' => $centiivOrderId]
            )[0] ?? null;
        }

        // Cross-reference user & Centiiv if 1Click was found first
        if ($oneClickData && !$userRow) {
            $refundTo = $oneClickData['quoteResponse']['quoteRequest']['refundTo'] ?? null;
            if ($refundTo && str_starts_with($refundTo, '0x')) {
                $userRow = $this->fetchAll(
                    "SELECT {$userCols}
                     FROM users WHERE aptos_address = :addr LIMIT 1",
                    ['addr' => $refundTo]
                )[0] ?? null;
                if ($userRow && !empty($userRow['phone_number'])) {
                    $userPhone = $userRow['phone_number'];
                }
            }
        }

        // 6. Transaction History (Strictly ordered from latest to oldest by created_at DESC)
        $historyRows = [];
        if ($userPhone) {
            $historyRows = $this->fetchAll(
                'SELECT * FROM transaction_history WHERE user_phone = :p ORDER BY created_at DESC LIMIT 30',
                ['p' => $userPhone]
            );
        } elseif ($looksLikeHash) {
            $historyRows = $this->fetchAll(
                'SELECT * FROM transaction_history WHERE tx_hash = :tx ORDER BY created_at DESC LIMIT 30',
                ['tx' => $q]
            );
        }
        if ($historyRows === [] && is_array($matchedHistoryRow)) {
            $historyRows = [$matchedHistoryRow];
        }

        // Calculate conversion rate, effective rate, fees (0.5%), and shortfall for offramp outcome
        if ($outcomeRow) {
            $usdt = (float) ($outcomeRow['usdt_debit'] ?? 0);
            $ngnFulfillment = (float) ($outcomeRow['fulfillment_ngn'] ?? $outcomeRow['target_ngn'] ?? 0);
            $centiivUsdc = (float) ($outcomeRow['centiiv_order_usdc'] ?? $outcomeRow['stellar_deposit_usdc'] ?? 0);
            $shortfallVal = (float) ($outcomeRow['ngn_shortfall'] ?? 0);

            // 1. Centiiv Conversion Rate at point of transaction
            $cRateNum = 0.0;
            if (!empty($centiivData['metadata']['rate'])) {
                $cRateNum = (float) $centiivData['metadata']['rate'];
            } elseif ($centiivUsdc > 0 && !empty($outcomeRow['quoted_ngn_at_pin'])) {
                $cRateNum = (float) $outcomeRow['quoted_ngn_at_pin'] / $centiivUsdc;
            }
            $outcomeRow['centiiv_rate'] = $cRateNum > 0 ? ('1 USDT = ₦' . number_format($cRateNum, 2)) : '—';

            // 2. Effective Rate (total payout in naira / coins sold)
            if ($usdt > 0 && $ngnFulfillment > 0) {
                $outcomeRow['effective_rate'] = '1 USDT = ₦' . number_format($ngnFulfillment / $usdt, 2);
            } else {
                $outcomeRow['effective_rate'] = '—';
            }

            // 3. Fees Charged (0.5% of actual offramped amount by Centiiv after 1Click conversion)
            $centiivEarnings = (float) ($centiivData['aggregator']['earnings'] ?? ($centiivUsdc * 0.005));
            if ($centiivEarnings > 0) {
                $outcomeRow['fees_charged'] = number_format($centiivEarnings, 5) . ' USDC (0.5%)';
            } else {
                $outcomeRow['fees_charged'] = '0.50%';
            }

            // 4. Shortfall (Separate field for Centiiv fee + 1Click swap difference)
            $outcomeRow['shortfall'] = '₦' . number_format($shortfallVal, 2);
        }

        $aptosWallet = (string) ($userRow['aptos_address'] ?? '');
        $stellarWallet = (string) ($userRow['stellar_address'] ?? '');
        $arcWallet = (string) ($userRow['arc_address'] ?? '');

        return [
            'query' => $q,
            'resolvedChain' => $resolvedChain,
            'user' => $userRow ? [
                'id' => (int) $userRow['Id'],
                'name' => $this->displayName($userRow),
                'phone' => (string) ($userRow['phone_number'] ?? ''),
                'wallet' => $aptosWallet,
                'walletShort' => $this->shortAddress($aptosWallet),
                'wallets' => [
                    'aptos' => $aptosWallet,
                    'aptosShort' => $this->shortAddress($aptosWallet),
                    'stellar' => $stellarWallet,
                    'stellarShort' => $this->shortAddress($stellarWallet),
                    'arc' => $arcWallet,
                    'arcShort' => $this->shortAddress($arcWallet),
                ],
                'kycTier' => (string) ($userRow['kyc_tier'] ?? 'tier0'),
                'createdAt' => (string) ($userRow['created_at'] ?? ''),
            ] : null,
            'offrampOutcome' => $outcomeRow,
            'pendingOrder' => $pendingOrderData,
            'pendingNative' => $pendingNative,
            'centiivOrderId' => $centiivOrderId,
            'centiiv' => $centiivData,
            'oneClickAddress' => $oneClickAddress,
            'oneClick' => $oneClickData,
            'history' => array_map(fn ($r) => $this->mapInspectHistoryRow($r), $historyRows),
        ];
    }

    private function looksLikeTxHash(string $q): bool
    {
        if (str_starts_with(strtolower($q), '0x') && strlen($q) >= 42) {
            return true;
        }
        // Stellar hashes are 64 hex without 0x
        return (bool) preg_match('/^[0-9a-fA-F]{64}$/', $q);
    }

    private function chainFromOfframpPath(string $path): ?string
    {
        $path = strtolower(trim($path));
        if ($path === '') {
            return null;
        }
        if (str_contains($path, 'stellar')) {
            return ChainNetwork::STELLAR;
        }
        if (str_contains($path, 'arc')) {
            return ChainNetwork::ARC;
        }
        if (str_contains($path, 'oneclick') || str_contains($path, 'panora') || str_contains($path, 'daya')) {
            return ChainNetwork::APTOS;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapInspectHistoryRow(array $r): array
    {
        $chain = ChainNetwork::resolve(
            (string) ($r['chain'] ?? ''),
            (string) ($r['explorer_url'] ?? '')
        );
        $hash = (string) ($r['tx_hash'] ?? '');
        $explorer = trim((string) ($r['explorer_url'] ?? ''));
        if ($explorer === '' && $hash !== '') {
            $explorer = ChainNetwork::explorerUrlForHash($chain, $hash);
        }
        $direction = (string) ($r['direction'] ?? '');
        $type = (string) ($r['activity_type'] ?? '');
        if ($type === '') {
            $type = $direction === 'in' ? 'deposit' : ($direction === 'out' ? 'withdrawal' : '');
        }

        return [
            'id' => (string) ($r['id'] ?? ''),
            'type' => $type,
            'direction' => $direction,
            'amount' => (string) ($r['amount'] ?? '0'),
            'currency' => (string) ($r['currency'] ?? 'USDT'),
            'chain' => $chain,
            'chainLabel' => ChainNetwork::label($chain),
            'status' => (string) ($r['status'] ?? 'completed'),
            'txHash' => $hash,
            'explorerUrl' => $explorer,
            'createdAt' => (string) ($r['created_at'] ?? ''),
        ];
    }

    /**
     * Locate native Stellar/Arc offramp pending row for inspector lookups.
     *
     * @return array{centiiv_order_id?: string, tx_hash?: string, tx_type?: string, chain?: string}|null
     */
    private function findNativeOfframpPendingContext(
        string $query,
        ?string $userPhone,
        ?string $centiivOrderId,
        ?string $chain
    ): ?array {
        $types = match ($chain) {
            ChainNetwork::STELLAR => "'offramp_stellar_centiiv'",
            ChainNetwork::ARC => "'offramp_arc_centiiv'",
            default => "'offramp_stellar_centiiv', 'offramp_arc_centiiv'",
        };

        $candidates = [];
        if ($centiivOrderId) {
            $candidates[] = $this->fetchAll(
                "SELECT id, tx_type, tx_hash, human_description
                 FROM pending_transactions
                 WHERE tx_type IN ({$types})
                   AND (human_description LIKE :like OR human_description LIKE :like2)
                 ORDER BY created_at DESC LIMIT 5",
                [
                    'like' => '%"centiiv_order_id":"' . $centiivOrderId . '"%',
                    'like2' => '%' . $centiivOrderId . '%',
                ]
            );
        }
        if ($this->looksLikeTxHash($query)) {
            $candidates[] = $this->fetchAll(
                "SELECT id, tx_type, tx_hash, human_description
                 FROM pending_transactions
                 WHERE tx_type IN ({$types})
                   AND (tx_hash = :q OR human_description LIKE :like)
                 ORDER BY created_at DESC LIMIT 5",
                ['q' => $query, 'like' => '%' . $query . '%']
            );
        }
        if ($userPhone && $centiivOrderId === null && !$this->looksLikeTxHash($query)) {
            $candidates[] = $this->fetchAll(
                "SELECT id, tx_type, tx_hash, human_description
                 FROM pending_transactions
                 WHERE user_phone = :p AND tx_type IN ({$types})
                 ORDER BY created_at DESC LIMIT 1",
                ['p' => $userPhone]
            );
        }

        foreach ($candidates as $rows) {
            foreach ($rows as $row) {
                $meta = json_decode((string) ($row['human_description'] ?? ''), true);
                if (!is_array($meta)) {
                    $meta = [];
                }
                $centiiv = trim((string) ($meta['centiiv_order_id'] ?? ''));
                $txType = (string) ($row['tx_type'] ?? '');
                $out = [
                    'tx_hash' => (string) ($row['tx_hash'] ?? ''),
                    'tx_type' => $txType,
                    'chain' => str_contains($txType, 'stellar')
                        ? ChainNetwork::STELLAR
                        : (str_contains($txType, 'arc') ? ChainNetwork::ARC : ($chain ?? ChainNetwork::APTOS)),
                ];
                if ($centiiv !== '') {
                    $out['centiiv_order_id'] = $centiiv;
                }
                if ($centiiv !== '' || $out['tx_hash'] !== '') {
                    return $out;
                }
            }
        }

        return null;
    }

    /**
     * Locate 1Click offramp pending row + deposit address for inspector lookups.
     *
     * @return array{deposit_address?: string, centiiv_order_id?: string, correlation_id?: string}|null
     */
    private function findOneClickPendingContext(
        string $query,
        ?string $userPhone,
        ?string $centiivOrderId,
        ?string $counterparty
    ): ?array {
        $candidates = [];

        if ($centiivOrderId) {
            $candidates[] = $this->fetchAll(
                "SELECT id, tx_type, tx_hash, recipient_address, human_description
                 FROM pending_transactions
                 WHERE tx_type IN ('offramp_oneclick_centiiv', 'offramp_panora_oneclick_centiiv')
                   AND (human_description LIKE :like OR human_description LIKE :like2)
                 ORDER BY created_at DESC LIMIT 5",
                [
                    'like' => '%"centiiv_order_id":"' . $centiivOrderId . '"%',
                    'like2' => '%' . $centiivOrderId . '%',
                ]
            );
        }

        if (str_starts_with(strtolower($query), '0x') && strlen($query) >= 42) {
            $candidates[] = $this->fetchAll(
                "SELECT id, tx_type, tx_hash, recipient_address, human_description
                 FROM pending_transactions
                 WHERE tx_type IN ('offramp_oneclick_centiiv', 'offramp_panora_oneclick_centiiv')
                   AND (tx_hash = :q OR recipient_address = :q OR human_description LIKE :like)
                 ORDER BY created_at DESC LIMIT 5",
                ['q' => $query, 'like' => '%' . $query . '%']
            );
        }

        if ($counterparty && str_starts_with(strtolower($counterparty), '0x')) {
            $candidates[] = $this->fetchAll(
                "SELECT id, tx_type, tx_hash, recipient_address, human_description
                 FROM pending_transactions
                 WHERE tx_type IN ('offramp_oneclick_centiiv', 'offramp_panora_oneclick_centiiv')
                   AND recipient_address = :c
                 ORDER BY created_at DESC LIMIT 3",
                ['c' => $counterparty]
            );
        }

        // Phone-only: only attach the latest 1Click pending when Centiiv id already matches,
        // or when query itself is the phone and we have no stronger selector.
        if ($userPhone && $centiivOrderId === null && !str_starts_with(strtolower($query), '0x')) {
            $candidates[] = $this->fetchAll(
                "SELECT id, tx_type, tx_hash, recipient_address, human_description
                 FROM pending_transactions
                 WHERE user_phone = :p
                   AND tx_type IN ('offramp_oneclick_centiiv', 'offramp_panora_oneclick_centiiv')
                 ORDER BY created_at DESC LIMIT 1",
                ['p' => $userPhone]
            );
        }

        foreach ($candidates as $rows) {
            foreach ($rows as $row) {
                $parsed = $this->parseOneClickPendingRow($row);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{deposit_address?: string, centiiv_order_id?: string, correlation_id?: string}|null
     */
    private function parseOneClickPendingRow(array $row): ?array
    {
        $meta = json_decode((string) ($row['human_description'] ?? ''), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $deposit = trim((string) ($row['recipient_address'] ?? ''));
        if ($deposit === '' || !str_starts_with(strtolower($deposit), '0x')) {
            $deposit = trim((string) ($meta['oneclick_deposit_address'] ?? $meta['deposit_address'] ?? ''));
        }
        if ($deposit !== '' && !str_starts_with(strtolower($deposit), '0x')) {
            $deposit = '';
        }

        $centiiv = trim((string) ($meta['centiiv_order_id'] ?? ''));
        $correlation = trim((string) (
            $meta['oneclick_correlation_id']
            ?? $meta['oneclick_correlation']
            ?? $meta['sendra_oneclick_correlation_id']
            ?? ''
        ));

        if ($deposit === '' && $centiiv === '' && $correlation === '') {
            return null;
        }

        $out = [];
        if ($deposit !== '') {
            $out['deposit_address'] = $deposit;
        }
        if ($centiiv !== '') {
            $out['centiiv_order_id'] = $centiiv;
        }
        if ($correlation !== '') {
            $out['correlation_id'] = $correlation;
        }

        return $out;
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

