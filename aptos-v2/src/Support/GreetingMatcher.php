<?php

declare(strict_types=1);

namespace AptosV2\Support;

final class GreetingMatcher
{
    public static function isGreeting(string $messageLower): bool
    {
        return (bool) preg_match('/\b(hi|hello|hey|good morning|good afternoon|start)\b/i', trim($messageLower));
    }
}
