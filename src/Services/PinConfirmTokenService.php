<?php

namespace App\Services;

/**
 * Signed, time-limited tokens for secure web PIN confirmation links.
 */
final class PinConfirmTokenService
{
    private const VERSION = 1;

    public static function secret(): string
    {
        $dedicated = trim((string) ($_ENV['PIN_CONFIRM_SECRET'] ?? getenv('PIN_CONFIRM_SECRET') ?: ''));
        if ($dedicated !== '') {
            return $dedicated;
        }

        return WebhookAuthService::internalApiSecret();
    }

    /**
     * @return string URL-safe token
     */
    public static function issue(string $pendingId, string $phone, int $expiresAt): string
    {
        $payload = [
            'v' => self::VERSION,
            'pid' => $pendingId,
            'phone' => $phone,
            'exp' => $expiresAt,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode PIN confirm token');
        }

        $body = self::base64UrlEncode($json);
        $sig = hash_hmac('sha256', $body, self::secret());

        return $body . '.' . $sig;
    }

    /**
     * @return ?array{pending_id: string, phone: string, exp: int}
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

        $pendingId = trim((string) ($data['pid'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $exp = (int) ($data['exp'] ?? 0);

        if ($pendingId === '' || $phone === '' || $exp <= 0) {
            return null;
        }

        if ($exp < time()) {
            return null;
        }

        return [
            'pending_id' => $pendingId,
            'phone' => $phone,
            'exp' => $exp,
        ];
    }

    public static function buildConfirmUrl(string $pendingId, string $phone, int $expiresAt): string
    {
        $base = self::publicBaseUrl();
        $token = self::issue($pendingId, $phone, $expiresAt);

        return rtrim($base, '/') . '/confirm-pin.php?token=' . rawurlencode($token);
    }

    public static function publicBaseUrl(): string
    {
        $base = trim(
            (string) (
                $_ENV['SENDRA_PUBLIC_BASE_URL']
                ?? getenv('SENDRA_PUBLIC_BASE_URL')
                ?: ($_ENV['LOGISTICS_PUBLIC_BASE_URL'] ?? getenv('LOGISTICS_PUBLIC_BASE_URL') ?: '')
            )
        );

        if ($base !== '') {
            return rtrim($base, '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        return $scheme . '://' . $host;
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
