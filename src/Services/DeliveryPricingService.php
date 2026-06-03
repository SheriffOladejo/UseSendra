<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LogisticsDriver;

/**
 * Estimates customer-facing delivery offers from distance, vehicle, and nearest rider placement.
 *
 * Tunable via env (see .env.example). Uses the same broadcastable driver pool as job pings
 * (approved / approved_test per LOGISTICS_TEST_COURIERS_ONLY).
 */
final class DeliveryPricingService
{
    public function __construct(
        private readonly float $baseNgn,
        private readonly float $perKmHaulNgn,
        private readonly float $perKmApproachNgn,
        private readonly float $kekeMultiplier,
        private readonly float $bicycleMultiplier,
        private readonly float $defaultApproachKm,
        private readonly float $approachCapKm,
        private readonly int $minNgn,
        private readonly int $maxNgn,
        private readonly int $roundToNgn,
    ) {
    }

    public static function fromEnv(): self
    {
        $g = static function (string $key, string $default): string {
            $v = $_ENV[$key] ?? getenv($key);

            return ($v !== false && $v !== null && $v !== '') ? (string) $v : $default;
        };

        return new self(
            (float) $g('LOGISTICS_PRICE_BASE_NGN', '500'),
            (float) $g('LOGISTICS_PRICE_PER_KM_HAUL_NGN', '120'),
            (float) $g('LOGISTICS_PRICE_PER_KM_APPROACH_NGN', '80'),
            (float) $g('LOGISTICS_PRICE_KEKE_MULTIPLIER', '1.25'),
            (float) $g('LOGISTICS_PRICE_BICYCLE_MULTIPLIER', '0.75'),
            (float) $g('LOGISTICS_PRICE_DEFAULT_APPROACH_KM', '3'),
            (float) $g('LOGISTICS_PRICE_APPROACH_CAP_KM', '15'),
            (int) $g('LOGISTICS_PRICE_MIN_NGN', '800'),
            (int) $g('LOGISTICS_PRICE_MAX_NGN', '50000'),
            max(1, (int) $g('LOGISTICS_PRICE_ROUND_TO_NGN', '50')),
        );
    }

    /**
     * Great-circle distance in kilometres (WGS84 sphere, same convention as LogisticsDriver::findNearby).
     */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  array<string, mixed> $requestRow delivery_requests row
     */
    public function estimateForDeliveryRequest(array $requestRow, LogisticsDriver $driverModel, float $searchRadiusKm = 50.0): int
    {
        $pickupLat = $this->nullableFloat($requestRow['pickup_lat'] ?? null);
        $pickupLng = $this->nullableFloat($requestRow['pickup_lng'] ?? null);
        $dropLat   = $this->nullableFloat($requestRow['dropoff_lat'] ?? null);
        $dropLng   = $this->nullableFloat($requestRow['dropoff_lng'] ?? null);
        $vehicle   = isset($requestRow['vehicle_type']) ? (string) $requestRow['vehicle_type'] : null;

        $nearestRiderKm = null;
        if ($pickupLat !== null && $pickupLng !== null) {
            $near = $driverModel->findNearby(
                $pickupLat,
                $pickupLng,
                $searchRadiusKm,
                $vehicle !== null && $vehicle !== '' ? $vehicle : null
            );
            if ($near !== [] && isset($near[0]['distance'])) {
                $nearestRiderKm = (float) $near[0]['distance'];
            }
        }

        return $this->estimateNgn($pickupLat, $pickupLng, $dropLat, $dropLng, $vehicle, $nearestRiderKm);
    }

    public function estimateNgn(
        ?float $pickupLat,
        ?float $pickupLng,
        ?float $dropoffLat,
        ?float $dropoffLng,
        ?string $vehicleType,
        ?float $nearestRiderKmToPickup
    ): int {
        $haulKm = 0.0;
        if (
            $pickupLat !== null && $pickupLng !== null
            && $dropoffLat !== null && $dropoffLng !== null
        ) {
            $haulKm = self::haversineKm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        }

        $approachKm = $nearestRiderKmToPickup;
        if ($approachKm === null || $approachKm < 0) {
            $approachKm = $this->defaultApproachKm;
        }
        $approachKm = min($approachKm, $this->approachCapKm);

        $subtotal = $this->baseNgn
            + $this->perKmHaulNgn * $haulKm
            + $this->perKmApproachNgn * $approachKm;

        $vehicleMult = match ($vehicleType) {
            'keke' => $this->kekeMultiplier,
            'bicycle' => $this->bicycleMultiplier,
            default => 1.0,
        };
        $raw         = $subtotal * $vehicleMult;

        // Temporary: round-trip pricing (out + back). Keep formula identical, just double.
        $rawRoundTrip = $raw * 2;

        $rounded = (int) round($rawRoundTrip / $this->roundToNgn) * $this->roundToNgn;

        return max($this->minNgn, min($this->maxNgn, $rounded));
    }

    private function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;

        return $f;
    }
}
