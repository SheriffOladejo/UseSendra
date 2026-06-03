<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Verifies Svix webhook signatures (Centiiv uses Svix for webhooks).
 *
 * @see https://docs.svix.com/receiving/verifying-payloads/how
 */
final class SvixWebhookVerifier
{
    /**
     * Max |now − Svix-Timestamp| allowed when verifying. Centiiv (and Svix retries) often deliver
     * minutes after signing; the old fixed 300s window caused 401 + no WhatsApp receipt when
     * strict verification is on (CENTIIV_WEBHOOK_ALLOW_UNSIGNED=0) while unsigned dev traffic still worked.
     *
     * Override with CENTIIV_SVIX_TIMESTAMP_TOLERANCE_SECONDS (min 300, max 604800).
     */
    private static function timestampToleranceSeconds(): int
    {
        $raw = $_ENV['CENTIIV_SVIX_TIMESTAMP_TOLERANCE_SECONDS'] ?? getenv('CENTIIV_SVIX_TIMESTAMP_TOLERANCE_SECONDS');
        if ($raw === false || $raw === null || (is_string($raw) && trim($raw) === '')) {
            return 10800;
        }
        $n = (int) $raw;

        return max(300, min($n, 604800));
    }

    /**
     * @param string $whsec Signing secret from Svix dashboard (prefix whsec_)
     */
    public static function verify(string $payload, string $whsec): bool
    {
        return self::verifyFailureMessage($payload, $whsec) === null;
    }

    /**
     * @return string|null null when the payload is authentic; otherwise a short reason for logs
     */
    public static function verifyFailureMessage(string $payload, string $whsec): ?string
    {
        if (!str_starts_with($whsec, 'whsec_')) {
            return 'Svix secret must start with whsec_';
        }

        $raw = base64_decode(substr($whsec, 6), true);
        if ($raw === false || $raw === '') {
            return 'Svix secret base64 decode failed';
        }

        $id = self::header('HTTP_SVIX_ID');
        $timestamp = self::header('HTTP_SVIX_TIMESTAMP');
        $signatureHeader = self::header('HTTP_SVIX_SIGNATURE');

        if ($id === '' || $timestamp === '' || $signatureHeader === '') {
            return 'missing Svix-Id, Svix-Timestamp, or Svix-Signature headers';
        }

        $ts = (int) $timestamp;
        $tol = self::timestampToleranceSeconds();
        if ($ts < 1 || abs(time() - $ts) > $tol) {
            $delta = time() - $ts;

            return 'Svix timestamp outside tolerance (|Δ|=' . abs($delta) . 's, limit=' . $tol . 's; check server clock or CENTIIV_SVIX_TIMESTAMP_TOLERANCE_SECONDS)';
        }

        $signedContent = $id . '.' . $timestamp . '.' . $payload;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $raw, true));

        foreach (explode(' ', $signatureHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'v1,')) {
                $sig = substr($part, 3);
                if ($sig !== '' && hash_equals($expected, $sig)) {
                    return null;
                }
            }
        }

        return 'Svix v1 signature mismatch';
    }

    /**
     * Try several endpoint secrets (e.g. logistics vs Sendra ramp) — Centiiv may use a different
     * Svix signing key per portal row while this app shares one URL.
     *
     * @param list<string> $whsecs
     */
    public static function verifyFailureMessageWithAnySecret(string $payload, array $whsecs): ?string
    {
        $list = [];
        foreach ($whsecs as $w) {
            if (!is_string($w)) {
                continue;
            }
            $w = trim($w);
            if ($w !== '' && str_starts_with($w, 'whsec_') && !in_array($w, $list, true)) {
                $list[] = $w;
            }
        }
        if ($list === []) {
            return 'no whsec_ secrets in list';
        }
        $last = null;
        foreach ($list as $w) {
            $last = self::verifyFailureMessage($payload, $w);
            if ($last === null) {
                return null;
            }
        }

        return ($last ?? 'Svix verification failed') . ' — none of ' . count($list) . ' whsec_ secret(s) matched';
    }

    private static function header(string $key): string
    {
        $v = $_SERVER[$key] ?? '';

        return is_string($v) ? $v : '';
    }
}
