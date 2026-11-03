<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders and sends the live Centiiv rate card on WhatsApp.
 */
final class SendraRateCardDelivery
{
    /**
     * @param array<string, mixed>|null $model Prebuilt model from {@see SendraRateCardFactory::build()}
     * @return array{sent_image: bool, model: array<string, mixed>}
     */
    public static function trySend(WhatsAppService $wa, string $phone, ?array $model = null): array
    {
        $phone = trim($phone);
        $model ??= SendraRateCardFactory::build();
        if ($phone === '' || empty($model['success']) || ($model['rows'] ?? []) === []) {
            return ['sent_image' => false, 'model' => $model];
        }

        try {
            $path = SendraPayoutReceiptImageService::renderRatesToTempFile($model);
            if ($path === null || !is_readable($path)) {
                error_log('SendraRateCardDelivery: failed to render rate card for ' . $phone);

                return ['sent_image' => false, 'model' => $model];
            }

            $res = $wa->sendImage($phone, $path, '📊 Sendra — live rates');
            @unlink($path);

            if ($res === false || (is_array($res) && empty($res['success']))) {
                error_log('SendraRateCardDelivery: sendImage failed for ' . $phone);

                return ['sent_image' => false, 'model' => $model];
            }

            return ['sent_image' => true, 'model' => $model];
        } catch (\Throwable $e) {
            error_log('SendraRateCardDelivery: ' . $e->getMessage());

            return ['sent_image' => false, 'model' => $model];
        }
    }
}
