<?php

declare(strict_types=1);

namespace AptosV2\Support;

final class ArcAddress
{
    public static function isValid(string $value): bool
    {
        $value = self::normalize($value);

        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $value);
    }

    public static function normalize(string $value): string
    {
        return strtolower(trim(str_replace([' ', '-'], '', $value)));
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function fromUser(array $user): ?string
    {
        $addr = self::normalize((string) ($user['arc_address'] ?? ''));

        return self::isValid($addr) ? $addr : null;
    }
}
