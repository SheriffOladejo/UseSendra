<?php

declare(strict_types=1);

namespace App\Services;

use AptosV2\Support\V2PinConfirmUrls;

/**
 * Signed, time-limited tokens for secure web KYC verification links.
 */
final class KycVerificationTokenService
{
    private const VERSION = 1;
    public const MODE_KYC = 'kyc';

    public static function secret(): string
    {
        $dedicated = trim((string) ($_ENV['KYC_VERIFY_SECRET'] ?? getenv('KYC_VERIFY_SECRET') ?: ''));
        if ($dedicated !== '') {
            return $dedicated;
        }

        return PinConfirmTokenService::secret();
    }

    public static function issue(string $phone, int $targetTier, int $expiresAt): string
    {
        $payload = [
            'v' => self::VERSION,
            'mode' => self::MODE_KYC,
            'phone' => $phone,
            'tier' => max(1, min(3, $targetTier)),
            'exp' => $expiresAt,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode KYC token');
        }

        $body = self::base64UrlEncode($json);
        $sig = hash_hmac('sha256', $body, self::secret());

        return $body . '.' . $sig;
    }

    /**
     * @return ?array{phone: string, tier: int, exp: int}
     */
    public static function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !str_contains($token, '.')) {
            return null;
        }

        [$body, $sig] = explode('.', $token, 2);
        if ($body === '' || $sig === '') {
            return null;
        }

        $secret = self::secret();
        if ($secret === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $json = self::base64UrlDecode($body);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        if ((int) ($data['v'] ?? 0) !== self::VERSION) {
            return null;
        }
        if (($data['mode'] ?? '') !== self::MODE_KYC) {
            return null;
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        $tier = (int) ($data['tier'] ?? 0);
        $exp = (int) ($data['exp'] ?? 0);

        if ($phone === '' || $exp <= 0 || $tier < 1 || $tier > 3) {
            return null;
        }
        if ($exp < time()) {
            return null;
        }

        return [
            'phone' => $phone,
            'tier' => $tier,
            'exp' => $exp,
        ];
    }

    public static function buildUrl(string $phone, int $targetTier, int $expiresAt): string
    {
        $base = V2PinConfirmUrls::publicBaseUrl();
        $token = self::issue($phone, $targetTier, $expiresAt);

        return rtrim($base, '/') . '/kyc-verify.php?token=' . rawurlencode($token);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
