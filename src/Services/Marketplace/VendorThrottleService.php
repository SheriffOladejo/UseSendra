<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Vendor;

/**
 * Auto-throttle vendors with high cancel/reject rates.
 */
final class VendorThrottleService
{
    private float $cancelRateThreshold;

    private int $consecutiveRejectPauseHours;

    public function __construct(
        private readonly Vendor $vendorModel,
        ?float $cancelRateThreshold = null,
        ?int $consecutiveRejectPauseHours = null
    ) {
        $this->cancelRateThreshold = $cancelRateThreshold ?? (float) (
            $_ENV['MARKETPLACE_VENDOR_CANCEL_RATE_THRESHOLD'] ?? getenv('MARKETPLACE_VENDOR_CANCEL_RATE_THRESHOLD') ?: '0.15'
        );
        $this->consecutiveRejectPauseHours = $consecutiveRejectPauseHours ?? (int) (
            $_ENV['MARKETPLACE_VENDOR_REJECT_PAUSE_HOURS'] ?? getenv('MARKETPLACE_VENDOR_REJECT_PAUSE_HOURS') ?: '24'
        );
    }

    public function recordVendorCancel(int $vendorId, string $reason): void
    {
        $this->vendorModel->incrementCancelMetric($vendorId);
        $vendor = $this->vendorModel->findById($vendorId);
        if (!$vendor) {
            return;
        }
        $orders = (int) ($vendor['order_count_30d'] ?? 0);
        $cancels = (int) ($vendor['cancel_count_30d'] ?? 0);
        if ($orders >= 5 && $orders > 0 && ($cancels / $orders) > $this->cancelRateThreshold) {
            $this->vendorModel->update($vendorId, ['status' => 'throttled']);
            error_log("Vendor {$vendorId} throttled: cancel rate " . round($cancels / $orders * 100, 1) . '%');
        }
    }

    public function recordVendorOrderAccepted(int $vendorId): void
    {
        $this->vendorModel->incrementOrderMetric($vendorId);
    }

    public function isVendorBrowsable(int $vendorId): bool
    {
        $v = $this->vendorModel->findById($vendorId);

        return $v !== null && ($v['status'] ?? '') === 'active';
    }
}
