<?php

declare(strict_types=1);

namespace AptosV2\Support;

use App\Services\PinConfirmTokenService;

/**
 * PIN confirm links for Aptos v2 flows (Logistics test number, future v2 production).
 *
 * Tokens use the same format as {@see PinConfirmTokenService}; only the page URL differs.
 */
final class V2PinConfirmUrls
{
    public static function aptosPhoneNumberId(): string
    {
        return (string) (
            $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID']
            ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID')
            ?: '851265871413834'
        );
    }

    public static function logisticsPhoneNumberId(): string
    {
        return (string) (
            $_ENV['WHATSAPP_LOGISTICS_PHONE_NUMBER_ID']
            ?? getenv('WHATSAPP_LOGISTICS_PHONE_NUMBER_ID')
            ?: '1097784993409171'
        );
    }

    /**
     * Default Meta phone_number_id for v2 PIN pages when origin is unknown.
     */
    public static function defaultPhoneNumberId(): string
    {
        if (self::envEnabled('APTOS_V2_ON_APTOS')) {
            return self::aptosPhoneNumberId();
        }
        if (self::envEnabled('APTOS_V2_TEST_ON_LOGISTICS')) {
            return self::logisticsPhoneNumberId();
        }

        return self::aptosPhoneNumberId();
    }

    public static function buildConfirmUrl(string $pendingId, string $phone, int $expiresAt): string
    {
        $base = self::publicBaseUrl();
        $token = PinConfirmTokenService::issueConfirm($pendingId, $phone, $expiresAt);

        return rtrim($base, '/') . '/confirm-pin-v2.php?token=' . rawurlencode($token);
    }

    /**
     * Public origin for v2 PIN pages. Prefers APTOS_V2_PUBLIC_BASE_URL so legacy
     * SENDRA_PUBLIC_BASE_URL (coinectra.stipendly.app) stays unchanged.
     */
    public static function publicBaseUrl(): string
    {
        $base = trim(
            (string) (
                $_ENV['APTOS_V2_PUBLIC_BASE_URL']
                ?? getenv('APTOS_V2_PUBLIC_BASE_URL')
                ?: (
                    $_ENV['LOGISTICS_PUBLIC_BASE_URL']
                    ?? getenv('LOGISTICS_PUBLIC_BASE_URL')
                    ?: ''
                )
            )
        );

        if ($base !== '') {
            return rtrim($base, '/');
        }

        return PinConfirmTokenService::publicBaseUrl();
    }

    /**
     * wa.me link after successful v2 PIN — returns user to the bot that started the flow.
     *
     * @param string|null $originPhoneNumberId Meta phone_number_id from pending tx or inbound webhook
     */
    public static function whatsappChatUrl(?string $originPhoneNumberId = null): string
    {
        $originPhoneNumberId = trim((string) $originPhoneNumberId);
        if ($originPhoneNumberId === self::aptosPhoneNumberId()) {
            return self::buildWaMe(self::aptosChatNumber());
        }
        if ($originPhoneNumberId === self::logisticsPhoneNumberId()) {
            return self::buildWaMe(self::logisticsChatNumber());
        }
        if (self::envEnabled('APTOS_V2_ON_APTOS')) {
            return self::buildWaMe(self::aptosChatNumber());
        }
        if (self::envEnabled('APTOS_V2_TEST_ON_LOGISTICS')) {
            return self::buildWaMe(self::logisticsChatNumber());
        }

        return self::buildWaMe(self::aptosChatNumber());
    }

    private static function aptosChatNumber(): string
    {
        return trim(
            (string) (
                $_ENV['SENDRA_WHATSAPP_CHAT_NUMBER']
                ?? getenv('SENDRA_WHATSAPP_CHAT_NUMBER')
                ?: '2348144111831'
            )
        );
    }

    private static function logisticsChatNumber(): string
    {
        return trim(
            (string) (
                $_ENV['SENDRA_LOGISTICS_WHATSAPP_CHAT_NUMBER']
                ?? getenv('SENDRA_LOGISTICS_WHATSAPP_CHAT_NUMBER')
                ?: '2347010100734'
            )
        );
    }

    private static function buildWaMe(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);

        return 'https://wa.me/' . $digits;
    }

    private static function envEnabled(string $key): bool
    {
        $raw = (string) ($_ENV[$key] ?? getenv($key) ?: '0');

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}
