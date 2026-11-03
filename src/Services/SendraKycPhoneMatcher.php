<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Match Prembly BVN/NIN phone fields against the user's WhatsApp number.
 */
final class SendraKycPhoneMatcher
{
    /**
     * Returns true when both numbers refer to the same Nigerian MSISDN.
     */
    public function matches(string $chatPhone, string $recordPhone): bool
    {
        $chat = $this->normalizeToNational10($chatPhone);
        $record = $this->normalizeToNational10($recordPhone);

        if ($chat === '' || $record === '') {
            return false;
        }

        return $chat === $record;
    }

    /**
     * Last 10 digits of Nigerian mobile (without leading 0 or 234).
     */
    public function normalizeToNational10(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '234') && strlen($digits) >= 13) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 11) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        if (strlen($digits) !== 10) {
            return '';
        }

        return $digits;
    }

    public function mismatchMessage(): string
    {
        return 'The phone number on your ID record does not match your Sendra WhatsApp number. '
            . 'Use the same phone linked to your BVN/NIN, or contact support@usesendra.com.';
    }
}
