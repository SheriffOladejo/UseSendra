<?php

declare(strict_types=1);

namespace AptosV2\Support;

final class ImagePayload
{
    /**
     * @return array{id: string, caption: ?string}|null
     */
    public static function parse(string $message): ?array
    {
        $data = json_decode($message, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['image']['id'])) {
            return null;
        }

        return [
            'id' => (string) $data['image']['id'],
            'caption' => isset($data['caption']) && is_string($data['caption']) ? $data['caption'] : null,
        ];
    }

    public static function parseNairaAmount(string $message): ?float
    {
        $t = trim($message);
        if (!preg_match('/^₦?\s*([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', $t, $m)) {
            return null;
        }
        $raw = str_replace([',', ' '], '', (string) $m[1]);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $amt = (float) $raw;

        return $amt >= 100 ? $amt : null;
    }

    public static function parseCaptionNairaAmount(?string $caption): ?float
    {
        if ($caption === null || trim($caption) === '') {
            return null;
        }

        $t = trim($caption);
        $amount = self::parseNairaAmount($t);
        if ($amount !== null) {
            return $amount;
        }

        if (preg_match('/^(?:send|pay)\s+([\d,\s]+(?:\.\d+)?)\s*(?:naira|ngn)?\s*$/iu', $t, $m)) {
            $raw = str_replace([',', ' '], '', (string) $m[1]);
            if ($raw !== '' && is_numeric($raw)) {
                $amt = (float) $raw;

                return $amt >= 100 ? $amt : null;
            }
        }

        $parsed = \AptosV2\Steps\Sell\NairaIntentParser::parse($t);

        return $parsed !== null ? (float) $parsed['amount_ngn'] : null;
    }
}
