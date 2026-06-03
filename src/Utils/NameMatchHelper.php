<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Loose name matching for rider bank account vs profile (same rules as NINStep).
 */
final class NameMatchHelper
{
    public static function namesLooselyMatch(string $profileFirst, string $profileLast, string $otherFirst, string $otherLast): bool
    {
        $pf = self::normNameToken($profileFirst);
        $pl = self::normNameToken($profileLast);
        $nf = self::normNameToken($otherFirst);
        $nl = self::normNameToken($otherLast);

        if ($pf === '' || $pl === '' || $nf === '' || $nl === '') {
            return true;
        }

        $lastOk = ($pl === $nl) || str_starts_with($pl, $nl) || str_starts_with($nl, $pl);
        $firstOk = ($pf === $nf) || str_starts_with($pf, $nf) || str_starts_with($nf, $pf);

        return $lastOk && $firstOk;
    }

    /**
     * Match rider profile against a single bank account holder name (often "SURNAME FIRSTNAME MIDDLE").
     */
    public static function profileMatchesBankAccountName(
        string $profileFirst,
        string $profileLast,
        string $bankAccountName
    ): bool {
        $bankAccountName = trim(preg_replace('/\s+/', ' ', $bankAccountName) ?? '');
        if ($bankAccountName === '') {
            return false;
        }
        $parts = preg_split('/\s+/', $bankAccountName) ?: [];
        if (count($parts) < 2) {
            return self::normNameToken($profileFirst) !== ''
                && str_contains(self::normNameToken($bankAccountName), self::normNameToken($profileFirst));
        }
        $bankFirst = (string) ($parts[0] ?? '');
        $bankLast = (string) ($parts[count($parts) - 1] ?? '');

        if (self::namesLooselyMatch($profileFirst, $profileLast, $bankFirst, $bankLast)) {
            return true;
        }

        $bankFirstAlt = (string) ($parts[count($parts) - 1] ?? '');
        $bankLastAlt = (string) ($parts[0] ?? '');

        return self::namesLooselyMatch($profileFirst, $profileLast, $bankFirstAlt, $bankLastAlt);
    }

    private static function normNameToken(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z]+/', '', $s) ?? '';

        return $s;
    }
}
