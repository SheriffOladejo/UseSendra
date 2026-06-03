<?php

namespace App\Services;

/**
 * Auth for webhook.php: Meta WhatsApp signatures and server-to-server internal routes.
 */
final class WebhookAuthService
{
    /**
     * Routes that are not Meta WhatsApp webhooks (deposit, Petra web callbacks, templates).
     */
    public static function isInternalPostRoute(string $requestUri, string $action = ''): bool
    {
        $action = strtolower(trim($action));

        if ($action !== '') {
            if (in_array($action, ['deposit', 'signup', 'sendsuccess', 'voucherclaim'], true)) {
                return true;
            }
        }

        $path = strtolower(parse_url($requestUri, PHP_URL_PATH) ?: $requestUri);

        foreach (['/deposit', '/signup', '/sendsuccess', '/voucher/claim', '/sendtemplate'] as $segment) {
            if (str_contains($path, $segment)) {
                return true;
            }
        }

        return false;
    }

    public static function metaAppSecret(): string
    {
        $raw = $_ENV['META_APP_SECRET']
            ?? $_ENV['WHATSAPP_APP_SECRET']
            ?? getenv('META_APP_SECRET')
            ?: getenv('WHATSAPP_APP_SECRET')
            ?: '';

        return trim((string) $raw);
    }

    public static function internalApiSecret(): string
    {
        return trim((string) ($_ENV['INTERNAL_API_SECRET'] ?? getenv('INTERNAL_API_SECRET') ?: ''));
    }

    public static function skipMetaSignatureVerify(): bool
    {
        return self::envFlag('WEBHOOK_SKIP_META_SIGNATURE');
    }

    public static function skipInternalAuth(): bool
    {
        return self::envFlag('WEBHOOK_SKIP_INTERNAL_AUTH');
    }

    /**
     * @return list<string>
     */
    public static function internalAuthCurlHeaders(): array
    {
        $secret = self::internalApiSecret();

        return [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . $secret,
        ];
    }

    /**
     * @return list<string> Allowed client IPs (exact match on REMOTE_ADDR).
     */
    public static function internalIpAllowlist(): array
    {
        $raw = trim((string) ($_ENV['WEBHOOK_INTERNAL_IP_ALLOWLIST'] ?? getenv('WEBHOOK_INTERNAL_IP_ALLOWLIST') ?: ''));
        if ($raw === '') {
            return ['127.0.0.1', '::1'];
        }

        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_filter($parts, static fn (string $ip): bool => $ip !== ''));
    }

    public static function requireInternalAuthOrExit(): void
    {
        if (self::skipInternalAuth()) {
            error_log('WEBHOOK: WEBHOOK_SKIP_INTERNAL_AUTH is on — internal route auth skipped (dev only)');

            return;
        }

        $secret = self::internalApiSecret();
        if ($secret === '') {
            error_log('WEBHOOK: INTERNAL_API_SECRET is not set; rejecting internal route');
            self::deny(503, 'Internal webhook auth not configured');
        }

        if (self::clientIpAllowed(self::internalIpAllowlist())) {
            return;
        }

        $provided = self::readInternalSecretFromRequest();
        if ($provided !== '' && hash_equals($secret, $provided)) {
            return;
        }

        error_log('WEBHOOK: internal route auth failed from ' . self::clientIp());
        self::deny(403, 'Forbidden');
    }

    public static function requireMetaSignatureOrExit(string $rawBody): void
    {
        if (self::skipMetaSignatureVerify()) {
            error_log('WEBHOOK: WEBHOOK_SKIP_META_SIGNATURE is on — Meta signature check skipped (dev only)');

            return;
        }

        $appSecret = self::metaAppSecret();
        if ($appSecret === '') {
            error_log('WEBHOOK: META_APP_SECRET is not set; rejecting unsigned WhatsApp POST');
            self::deny(503, 'WhatsApp webhook signature verification not configured');
        }

        if (!self::verifyMetaSignature($rawBody, $appSecret)) {
            error_log('WEBHOOK: X-Hub-Signature-256 verification failed from ' . self::clientIp());
            self::deny(403, 'Forbidden');
        }
    }

    public static function verifyMetaSignature(string $rawBody, string $appSecret): bool
    {
        $header = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
        if ($header === '' || !str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $header);
    }

    private static function readInternalSecretFromRequest(): string
    {
        $fromHeader = trim((string) ($_SERVER['HTTP_X_INTERNAL_SECRET'] ?? ''));
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        $auth = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * @param list<string> $allowlist
     */
    private static function clientIpAllowed(array $allowlist): bool
    {
        $ip = self::clientIp();

        return $ip !== '' && in_array($ip, $allowlist, true);
    }

    private static function clientIp(): string
    {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private static function envFlag(string $name): bool
    {
        $v = strtolower(trim((string) ($_ENV[$name] ?? getenv($name) ?: '')));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private static function deny(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
        exit;
    }
}
