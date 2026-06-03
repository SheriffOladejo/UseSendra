<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Logistics vehicle types (rider onboarding + customer courier search).
 */
final class VehicleTypeHelper
{
    public const MOTORCYCLE = 'motorcycle';

    public const KEKE = 'keke';

    public const BICYCLE = 'bicycle';

    /** @var list<string> */
    public const ALL = [self::MOTORCYCLE, self::KEKE, self::BICYCLE];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /**
     * Bicycle couriers do not provide driver's licence or vehicle plate / hackney permit.
     */
    public static function requiresLicenseAndPlate(string $type): bool
    {
        return $type !== self::BICYCLE;
    }

    public static function normalize(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        $map = [
            'motorcycle' => self::MOTORCYCLE,
            'bike' => self::MOTORCYCLE,
            '🏍' => self::MOTORCYCLE,
            '🏍️' => self::MOTORCYCLE,
            'keke' => self::KEKE,
            '🛺' => self::KEKE,
            'bicycle' => self::BICYCLE,
            'cycle' => self::BICYCLE,
            '🚲' => self::BICYCLE,
        ];

        return $map[$raw] ?? null;
    }

    public static function displayLabel(string $type): string
    {
        return match ($type) {
            self::KEKE => 'Keke',
            self::BICYCLE => 'Bicycle',
            default => 'Bike',
        };
    }

    /**
     * Customer-facing list rows (id => title, max 24 chars for list title).
     *
     * @return array<string, string>
     */
    public static function customerListRows(): array
    {
        return [
            self::MOTORCYCLE => '🏍 Bike',
            self::KEKE => '🛺 Keke',
            self::BICYCLE => '🚲 Bicycle',
        ];
    }

    /**
     * Rider onboarding buttons (max 3 for WhatsApp reply buttons).
     *
     * @return array<string, string>
     */
    public static function driverButtonLabels(\App\Utils\Translator $t): array
    {
        return [
            self::MOTORCYCLE => $t->get('vehicle_motorcycle'),
            self::KEKE => $t->get('vehicle_keke'),
            self::BICYCLE => $t->get('vehicle_bicycle'),
        ];
    }
}
