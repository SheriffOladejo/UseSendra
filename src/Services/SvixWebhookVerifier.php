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
     * @param string $whsec Signing secret from Svix dashboard (prefix whsec_)
     */
    public static function verify(string $payload, string $whsec): bool
    {
        if (!str_starts_with($whsec, 'whsec_')) {
            return false;
        }

        $raw = base64_decode(substr($whsec, 6), true);
        if ($raw === false || $raw === '') {
            return false;
        }

        $id = self::header('HTTP_SVIX_ID');
        $timestamp = self::header('HTTP_SVIX_TIMESTAMP');
        $signatureHeader = self::header('HTTP_SVIX_SIGNATURE');

        if ($id === '' || $timestamp === '' || $signatureHeader === '') {
            return false;
        }

        $ts = (int) $timestamp;
        if ($ts < 1 || abs(time() - $ts) > 300) {
            return false;
        }

        $signedContent = $id . '.' . $timestamp . '.' . $payload;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $raw, true));

        foreach (explode(' ', $signatureHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'v1,')) {
                $sig = substr($part, 3);
                if ($sig !== '' && hash_equals($expected, $sig)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function header(string $key): string
    {
        $v = $_SERVER[$key] ?? '';

        return is_string($v) ? $v : '';
    }
}
