<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders and sends buy (on-ramp) receipt images on WhatsApp after 1Click SUCCESS.
 */
final class SendraBuyReceiptDelivery
{
    /**
     * @param array<string, mixed> $meta pending row human_description
     * @param array<string, mixed>|null $oneClickStatusData decoded 1Click status payload
     */
    public static function trySend(WhatsAppService $wa, string $phone, array $meta, ?array $oneClickStatusData = null): bool
    {
        $phone = trim($phone);
        if ($phone === '' || !empty($meta['buy_receipt_sent'])) {
            return false;
        }

        try {
            $model = SendraCentiivBuyReceiptFactory::buildFromOnrampMeta($meta, $oneClickStatusData);
            $path = SendraPayoutReceiptImageService::renderBuyToTempFile($model);
            if ($path === null || !is_readable($path)) {
                error_log('SendraBuyReceiptDelivery: failed to render buy receipt for ' . $phone);

                return false;
            }

            $res = $wa->sendImage($phone, $path, '✅ Sendra — buy receipt');
            @unlink($path);

            if ($res === false) {
                error_log('SendraBuyReceiptDelivery: sendImage upload failed for ' . $phone);

                return false;
            }
            if (is_array($res) && empty($res['success'])) {
                error_log('SendraBuyReceiptDelivery: sendImage API failed for ' . $phone);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            error_log('SendraBuyReceiptDelivery: ' . $e->getMessage());

            return false;
        }
    }
}
