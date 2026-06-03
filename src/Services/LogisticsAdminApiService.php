<?php

namespace App\Services;

use PDO;

/**
 * Read-only admin aggregates and lists for sendra_logistics_db.
 */
final class LogisticsAdminApiService
{
    public function __construct(private PDO $db) {}

    public function overview(): array
    {
        $users = (int) $this->scalar('SELECT COUNT(*) FROM users');
        $totalRiders = (int) $this->scalar('SELECT COUNT(*) FROM drivers');
        $activeRiders = (int) $this->scalar(
            "SELECT COUNT(*) FROM drivers WHERE status = 'active'
             AND verification_status IN ('approved', 'approved_test', 'approved_paused')"
        );
        $pendingVerification = (int) $this->scalar(
            "SELECT COUNT(*) FROM drivers WHERE verification_status = 'pending'"
        );
        $totalOrders = (int) $this->scalar(
            "SELECT COUNT(*) FROM delivery_requests WHERE status NOT IN ('draft')"
        );
        $delivered = (int) $this->scalar(
            "SELECT COUNT(*) FROM delivery_requests WHERE status = 'delivered'"
        );
        $revenueNgn = (float) $this->scalar(
            "SELECT COALESCE(SUM(offered_price), 0) FROM delivery_requests WHERE status = 'delivered'"
        );
        $successRate = $totalOrders > 0 ? round(($delivered / $totalOrders) * 100, 1) : 0.0;

        return [
            'stats' => [
                'totalUsers' => $users,
                'activeRiders' => $activeRiders,
                'totalRiders' => $totalRiders,
                'pendingVerification' => $pendingVerification,
                'revenueNgn' => $revenueNgn,
                'totalOrders' => $totalOrders,
                'successRate' => $successRate,
            ],
            'recentActivities' => $this->recentActivities(12),
        ];
    }

    public function customers(int $limit = 100, int $offset = 0, ?string $search = null): array
    {
        $where = '';
        $params = [];
        if ($search !== null && $search !== '') {
            $where = ' WHERE (u.phone LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q OR u.whatsapp_name LIKE :q) ';
            $params['q'] = '%' . $search . '%';
        }

        $countSql = 'SELECT COUNT(*) FROM users u' . $where;
        $totalCustomers = (int) $this->scalar($countSql, $params);
        $totalOrders = (int) $this->scalar(
            "SELECT COUNT(*) FROM delivery_requests WHERE status NOT IN ('draft')"
        );
        $avgRating = $this->scalar(
            'SELECT ROUND(AVG(rating), 1) FROM logistics_reviews WHERE reviewee_user_id IS NOT NULL'
        );

        $sql = "
            SELECT
                u.id,
                u.phone,
                u.first_name,
                u.last_name,
                u.whatsapp_name,
                u.status,
                u.total_orders,
                COALESCE(rv.avg_rating, 0) AS avg_rating
            FROM users u
            LEFT JOIN (
                SELECT reviewee_user_id, ROUND(AVG(rating), 1) AS avg_rating
                FROM logistics_reviews
                WHERE reviewee_user_id IS NOT NULL
                GROUP BY reviewee_user_id
            ) rv ON rv.reviewee_user_id = u.id
            {$where}
            ORDER BY u.created_at DESC
            LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = $this->fetchAll($sql, $params);

        return [
            'stats' => [
                'totalCustomers' => $totalCustomers,
                'totalOrders' => $totalOrders,
                'averageRating' => $avgRating !== null ? (float) $avgRating : 0.0,
            ],
            'customers' => array_map(fn (array $r) => [
                'id' => (int) $r['id'],
                'name' => $this->displayName($r),
                'number' => $r['phone'],
                'status' => $r['status'] === 'active' ? 'Active' : 'Inactive',
                'orders' => (int) ($r['total_orders'] ?? 0),
                'avgRating' => (float) ($r['avg_rating'] ?? 0),
            ], $rows),
        ];
    }

    public function riders(int $limit = 100, int $offset = 0, ?string $search = null): array
    {
        $where = '';
        $params = [];
        if ($search !== null && $search !== '') {
            $where = ' WHERE (d.phone LIKE :q OR d.first_name LIKE :q OR d.last_name LIKE :q) ';
            $params['q'] = '%' . $search . '%';
        }

        $totalRiders = (int) $this->scalar('SELECT COUNT(*) FROM drivers' . $where, $params);
        $pendingVerification = (int) $this->scalar(
            "SELECT COUNT(*) FROM drivers WHERE verification_status = 'pending'"
        );
        $totalDeliveries = (int) $this->scalar(
            "SELECT COUNT(*) FROM delivery_requests WHERE status = 'delivered'"
        );
        $totalEarnings = (float) $this->scalar(
            'SELECT COALESCE(SUM(wallet_balance_ngn), 0) FROM drivers'
        );
        $avgRating = $this->scalar('SELECT ROUND(AVG(rating), 1) FROM drivers WHERE rating IS NOT NULL');

        $sql = "
            SELECT
                d.id, d.phone, d.first_name, d.last_name, d.whatsapp_name,
                d.verification_status, d.vehicle_type, d.vehicle_brand_model,
                d.vehicle_color, d.vehicle_plate_number,
                d.preferred_latitude, d.preferred_longitude,
                d.total_trips, d.rating, d.status
            FROM drivers d
            {$where}
            ORDER BY d.created_at DESC
            LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = $this->fetchAll($sql, $params);

        return [
            'stats' => [
                'totalRiders' => $totalRiders,
                'pendingVerification' => $pendingVerification,
                'totalDeliveries' => $totalDeliveries,
                'totalEarningsNgn' => $totalEarnings,
                'averageRating' => $avgRating !== null ? (float) $avgRating : 0.0,
            ],
            'riders' => array_map(fn (array $r) => [
                'id' => (int) $r['id'],
                'name' => $this->displayName($r),
                'number' => $r['phone'],
                'verification' => $this->mapVerification($r['verification_status']),
                'vehicle' => $this->formatVehicleType($r['vehicle_type']),
                'vehicleDetail' => $this->vehicleDetail($r),
                'location' => $this->formatLocation($r),
                'trips' => (int) ($r['total_trips'] ?? 0),
                'rating' => (float) ($r['rating'] ?? 0),
            ], $rows),
        ];
    }

    public function orders(int $limit = 100, int $offset = 0, ?string $search = null): array
    {
        $where = " WHERE dr.status NOT IN ('draft') ";
        $params = [];
        if ($search !== null && $search !== '') {
            $where .= ' AND (dr.id LIKE :q OR dr.pickup_address LIKE :q OR dr.dropoff_address LIKE :q
                OR u.phone LIKE :q OR u.first_name LIKE :q) ';
            $params['q'] = '%' . $search . '%';
        }

        $stats = [
            'total' => (int) $this->scalar(
                "SELECT COUNT(*) FROM delivery_requests dr {$where}",
                $params
            ),
            'completed' => (int) $this->scalar(
                "SELECT COUNT(*) FROM delivery_requests dr {$where} AND dr.status = 'delivered'",
                $params
            ),
            'inTransit' => (int) $this->scalar(
                "SELECT COUNT(*) FROM delivery_requests dr {$where}
                 AND dr.status IN ('driver_assigned', 'in_transit')",
                $params
            ),
            'pending' => (int) $this->scalar(
                "SELECT COUNT(*) FROM delivery_requests dr {$where}
                 AND dr.status IN ('searching_driver', 'driver_payment_pending', 'awaiting_rebroadcast')",
                $params
            ),
            'cancelled' => (int) $this->scalar(
                "SELECT COUNT(*) FROM delivery_requests dr {$where}
                 AND dr.status IN ('cancelled', 'payment_expired')",
                $params
            ),
        ];

        $sql = "
            SELECT
                dr.id, dr.status, dr.pickup_address, dr.dropoff_address,
                dr.offered_price, dr.created_at,
                u.first_name, u.last_name, u.whatsapp_name, u.phone AS user_phone
            FROM delivery_requests dr
            LEFT JOIN users u ON u.id = dr.user_id
            {$where}
            ORDER BY dr.created_at DESC
            LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = $this->fetchAll($sql, $params);

        return [
            'stats' => $stats,
            'orders' => array_map(fn (array $r) => [
                'id' => '#ORD-' . $r['id'],
                'orderId' => (int) $r['id'],
                'customer' => $this->displayName($r) !== '' ? $this->displayName($r) : ($r['user_phone'] ?? '—'),
                'status' => $this->mapOrderStatus($r['status']),
                'pickup' => $this->shortAddress($r['pickup_address']),
                'dropoff' => $this->shortAddress($r['dropoff_address']),
                'amount' => $r['offered_price'] !== null
                    ? '₦' . number_format((float) $r['offered_price'], 0)
                    : '—',
                'createdAt' => $this->formatTime($r['created_at']),
            ], $rows),
        ];
    }

    public function analytics(int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $where = " WHERE t.status = 'completed' ";
        $params = [];
        if ($search !== null && $search !== '') {
            $where .= ' AND (t.reference LIKE :q OR t.id LIKE :q) ';
            $params['q'] = '%' . $search . '%';
        }

        $inflow = (float) $this->scalar(
            "SELECT COALESCE(SUM(amount_ngn), 0) FROM rider_wallet_transactions
             WHERE status = 'completed' AND type IN ('fund', 'adjustment') AND amount_ngn > 0"
        );
        $outflow = (float) $this->scalar(
            "SELECT COALESCE(SUM(ABS(amount_ngn)), 0) FROM rider_wallet_transactions
             WHERE status = 'completed' AND type IN ('withdraw', 'acceptance_fee')
             OR (type = 'adjustment' AND amount_ngn < 0)"
        );
        $balance = (float) $this->scalar('SELECT COALESCE(SUM(wallet_balance_ngn), 0) FROM drivers');

        $sql = "
            SELECT t.id, t.reference, t.type, t.amount_ngn, t.created_at, t.centiiv_resource_id
            FROM rider_wallet_transactions t
            {$where}
            ORDER BY t.created_at DESC
            LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = $this->fetchAll($sql, $params);
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM rider_wallet_transactions t {$where}",
            $params
        );

        return [
            'stats' => [
                'totalInflowNgn' => $inflow,
                'totalOutflowNgn' => $outflow,
                'balanceNgn' => $balance,
            ],
            'pagination' => [
                'page' => (int) floor($offset / max($limit, 1)) + 1,
                'pageSize' => $limit,
                'total' => $total,
                'totalPages' => (int) max(1, ceil($total / max($limit, 1))),
            ],
            'transactions' => array_map(fn (array $r) => [
                'id' => substr((string) $r['reference'], 0, 6) ?: (string) $r['id'],
                'amount' => '₦ ' . number_format(abs((float) $r['amount_ngn']), 2),
                'time' => $this->relativeTime($r['created_at']),
                'type' => $this->txType($r['type'], (float) $r['amount_ngn']),
                'tx' => $r['centiiv_resource_id']
                    ? 'https://centiiv.com/' . $r['centiiv_resource_id']
                    : null,
            ], $rows),
        ];
    }

    public function updateRiderVerification(int $driverId, string $action): bool
    {
        $status = match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'approve_test' => 'approved_test',
            default => throw new \InvalidArgumentException('Invalid action'),
        };

        $stmt = $this->db->prepare(
            'UPDATE drivers SET verification_status = :status WHERE id = :id'
        );

        return $stmt->execute(['status' => $status, 'id' => $driverId]);
    }

    private function recentActivities(int $limit): array
    {
        $sql = "
            SELECT kind, title, meta, occurred_at FROM (
                SELECT
                    'delivery_completed' AS kind,
                    CONCAT('Delivery completed - User: ', COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))), ''), u.whatsapp_name, u.phone)) AS title,
                    CONCAT('Order Id: ', dr.id, ' • Rider: ', COALESCE(NULLIF(TRIM(CONCAT(COALESCE(d.first_name,''), ' ', COALESCE(d.last_name,''))), ''), d.phone, 'Nil')) AS meta,
                    COALESCE(dr.dropoff_confirmed_at, dr.created_at) AS occurred_at
                FROM delivery_requests dr
                LEFT JOIN users u ON u.id = dr.user_id
                LEFT JOIN drivers d ON d.id = dr.reserved_driver_id
                WHERE dr.status = 'delivered'

                UNION ALL

                SELECT
                    'new_delivery_request',
                    CONCAT('New delivery request - User: ', COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))), ''), u.whatsapp_name, u.phone)),
                    CONCAT('Order Id: ', dr.id, ' • Rider: Nil'),
                    dr.created_at
                FROM delivery_requests dr
                LEFT JOIN users u ON u.id = dr.user_id
                WHERE dr.status NOT IN ('draft')

                UNION ALL

                SELECT
                    'new_user_signup',
                    CONCAT('New user signup - User: ', COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))), ''), whatsapp_name, phone)),
                    'Order Id: Nil • Rider: Nil',
                    created_at
                FROM users

                UNION ALL

                SELECT
                    'driver_verification',
                    CONCAT('Driver verification ', verification_status, ' - ', COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))), ''), phone)),
                    'Order Id: Nil • Rider: Nil',
                    created_at
                FROM drivers
                WHERE verification_status IN ('approved', 'approved_test', 'rejected')
            ) AS activity
            ORDER BY occurred_at DESC
            LIMIT " . (int) $limit;

        $rows = $this->fetchAll($sql);

        return array_map(fn (array $r) => [
            'kind' => $r['kind'],
            'title' => $r['title'],
            'meta' => $r['meta'],
            'time' => $this->formatTime($r['occurred_at']),
        ], $rows);
    }

    private function displayName(array $row): string
    {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if (!empty($row['whatsapp_name'])) {
            return (string) $row['whatsapp_name'];
        }

        return (string) ($row['phone'] ?? '');
    }

    private function mapVerification(string $status): string
    {
        return match ($status) {
            'approved', 'approved_test', 'approved_paused' => 'Verified',
            'rejected' => 'Rejected',
            default => 'Pending',
        };
    }

    private function formatVehicleType(?string $type): string
    {
        if ($type === null || $type === '') {
            return '—';
        }

        return ucfirst($type === 'bike' ? 'Motorcycle' : $type);
    }

    private function vehicleDetail(array $r): string
    {
        $parts = array_filter([
            $r['vehicle_brand_model'] ?? null,
            $r['vehicle_color'] ?? null,
            $r['vehicle_plate_number'] ?? null,
        ]);

        return $parts !== [] ? implode(' ', $parts) : '—';
    }

    private function formatLocation(array $r): string
    {
        if (!empty($r['preferred_latitude']) && !empty($r['preferred_longitude'])) {
            return round((float) $r['preferred_latitude'], 4) . ', ' . round((float) $r['preferred_longitude'], 4);
        }

        return '—';
    }

    private function mapOrderStatus(string $status): string
    {
        return match ($status) {
            'delivered' => 'Completed',
            'driver_assigned', 'in_transit' => 'In transit',
            'cancelled', 'payment_expired' => 'Cancelled',
            default => 'Pending',
        };
    }

    private function shortAddress(?string $addr): string
    {
        if ($addr === null || $addr === '') {
            return '—';
        }
        $addr = preg_replace('/^\([^)]+\)\s*/', '', $addr) ?? $addr;
        if (strlen($addr) > 40) {
            return substr($addr, 0, 37) . '...';
        }

        return $addr;
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

    private function relativeTime(?string $datetime): string
    {
        if ($datetime === null) {
            return '—';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return $diff . ' secs ago';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . ' mins ago';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . ' hrs ago';
        }

        return (int) floor($diff / 86400) . ' days ago';
    }

    private function txType(string $type, float $amount): string
    {
        if (in_array($type, ['fund'], true) || ($type === 'adjustment' && $amount > 0)) {
            return 'In';
        }

        return 'Out';
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
