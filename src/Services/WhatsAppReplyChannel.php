<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Stamps pending-transaction metadata with the WhatsApp business phone_number_id
 * that started the flow so async workers and webhooks reply on the correct bot.
 */
final class WhatsAppReplyChannel
{
    /**
     * Merge {@see origin_whatsapp_phone_id} into pending human_description JSON.
     */
    public static function enrichHumanDescription(string $humanDescription, string $phoneNumberId): string
    {
        $phoneNumberId = trim($phoneNumberId);
        $raw = trim($humanDescription);
        if ($raw === '') {
            $meta = [];
        } else {
            $decoded = json_decode($raw, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        if ($phoneNumberId !== '') {
            $meta['origin_whatsapp_phone_id'] = $phoneNumberId;
        }

        return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
