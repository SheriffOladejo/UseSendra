<?php

declare(strict_types=1);

namespace AptosV2\Support;

/**
 * Nigerian / international phone normalization (ported from legacy MessageController).
 */
final class PhoneNormalizer
{
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = '234' . substr($digits, 1);
        } elseif (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '254' . substr($digits, 1);
        }

        return $digits;
    }

    public static function resolveFiat(string $phone): string
    {
        $norm = self::normalize($phone);
        if (str_starts_with($norm, '254')) {
            return 'KES';
        }
        if (str_starts_with($norm, '233')) {
            return 'GHS';
        }
        if (preg_match('/^(225|221|237|229|228|226)/', $norm)) {
            return 'XOF';
        }

        return 'NGN';
    }
}
