<?php

declare(strict_types=1);

namespace AptosV2\Support;

final class ChangePinIntent
{
    public static function matches(string $message): bool
    {
        $message = strtolower(trim($message));

        if (preg_match('/^(?:\/)?change(?:\s+my)?(?:\s+transaction)?\s+pin$/', $message)) {
            return true;
        }

        return (bool) preg_match('/^i\s+want\s+to\s+change\s+my(?:\s+transaction)?\s+pin$/', $message);
    }
}
