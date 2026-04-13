<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pickup QR / typed code payload: SENDRA_PICKUP_{requestId}[.{token}]
 *
 * When LOGISTICS_PICKUP_QR_SECRET is set, a short HMAC token is appended so a code
 * cannot be forged from the public job id alone. Without the secret (e.g. local dev),
 * payload stays SENDRA_PICKUP_{id} only.
 *
 * When a secret is configured, pickup confirmation is QR-photo only (no typed fallback for senders).
 */
final class PickupQrCodeService
{
    /** True when signed payloads are used — senders must photograph the rider's QR, not type a code. */
    public static function pickupConfirmViaQrOnly(): bool
    {
        return self::secret() !== '';
    }

    public static function secret(): string
    {
        $s = $_ENV['LOGISTICS_PICKUP_QR_SECRET'] ?? getenv('LOGISTICS_PICKUP_QR_SECRET');

        return ($s !== false && $s !== null && $s !== '') ? (string) $s : '';
    }

    public static function payloadForRequest(int $requestId): string
    {
        $base = 'SENDRA_PICKUP_' . $requestId;
        $sec  = self::secret();
        if ($sec === '') {
            return $base;
        }

        return $base . '.' . self::signToken($requestId, $sec);
    }

    public static function signToken(int $requestId, string $secret): string
    {
        $raw = hash_hmac('sha256', 'sendra_pickup_v1|' . $requestId, $secret, true);

        return rtrim(strtr(base64_encode(substr($raw, 0, 14)), '+/', '-_'), '=');
    }

    /**
     * @return int|null Parsed request id if valid, null if invalid
     */
    public static function parseAndVerify(string $messageLower): ?int
    {
        $messageLower = strtolower(trim($messageLower));
        if (!preg_match('/^sendra_pickup_(\d+)(?:\.([a-z0-9_-]+))?$/i', $messageLower, $m)) {
            return null;
        }
        $id  = (int) $m[1];
        $tok = isset($m[2]) ? (string) $m[2] : '';
        $sec = self::secret();

        if ($sec === '') {
            return $tok === '' ? $id : null;
        }

        if ($tok !== '' && hash_equals(self::signToken($id, $sec), $tok)) {
            return $id;
        }

        // Migration: allow unsigned SENDRA_PICKUP_{id} only when LOGISTICS_PICKUP_QR_STRICT=false
        $strictRaw = $_ENV['LOGISTICS_PICKUP_QR_STRICT'] ?? getenv('LOGISTICS_PICKUP_QR_STRICT');
        $strict    = $strictRaw === false || $strictRaw === null || $strictRaw === ''
            ? true
            : filter_var($strictRaw, FILTER_VALIDATE_BOOLEAN);
        if (!$strict && $tok === '') {
            return $id;
        }

        return null;
    }
}
