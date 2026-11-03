<?php

declare(strict_types=1);

namespace AptosV2\Support;

final class StellarAddress
{
    /**
     * Strip WhatsApp wrapping / paste noise (spaces, hyphens, zero-width) and uppercase.
     */
    public static function normalize(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/[\s\-_\x{200B}\x{FEFF}]+/u', '', $s) ?? $s;

        return strtoupper($s);
    }

    public static function isValid(string $s): bool
    {
        $s = self::normalize($s);

        return (bool) preg_match('/^[GC][A-Z2-7]{55}$/', $s);
    }

    public static function isContract(string $s): bool
    {
        $s = self::normalize($s);

        return self::isValid($s) && $s[0] === 'C';
    }

    public static function isClassic(string $s): bool
    {
        $s = self::normalize($s);

        return self::isValid($s) && $s[0] === 'G';
    }

    public static function fromUser(array $user): ?string
    {
        $addr = self::normalize((string) ($user['stellar_address'] ?? ''));

        return $addr !== '' && self::isValid($addr) ? $addr : null;
    }
}
