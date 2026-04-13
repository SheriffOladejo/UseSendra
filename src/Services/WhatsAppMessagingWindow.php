<?php

declare(strict_types=1);

namespace App\Services;

/**
 * WhatsApp Cloud API customer-care window: after ~24h without a user message,
 * only template (or paid) messages may start a conversation.
 *
 * We treat the window as slightly shorter (23h 30m) so proactive session
 * messages stay under Meta limits if clocks skew.
 */
final class WhatsAppMessagingWindow
{
    /** 23 hours 30 minutes in seconds (grace under the 24h rule). */
    public const WINDOW_SECONDS = 23 * 3600 + 30 * 60;

    /**
     * @param string|null $lastUserActivityAt MySQL datetime (e.g. drivers.last_message_at, whatsapp_sessions.updated_at)
     */
    public static function isWithinCustomerCareWindow(?string $lastUserActivityAt): bool
    {
        if ($lastUserActivityAt === null || $lastUserActivityAt === '') {
            return false;
        }

        $last = strtotime($lastUserActivityAt);
        if ($last === false) {
            return false;
        }

        return (time() - $last) < self::WINDOW_SECONDS;
    }
}
