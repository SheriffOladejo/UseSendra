<?php

declare(strict_types=1);

namespace App\Services;

/**
 * After Daya (or another rail) credits the Sendra NGN pool, pay the user exactly ₦X
 * via the existing Centiiv bank disburse.
 */
final class SendraIntermediaryDisburse
{
    /**
     * @param array<string, mixed> $ctx
     */
    public static function payUserFromPool(\PDO $db, WhatsAppService $wa, array $ctx): bool
    {
        $phone = trim((string) ($ctx['user_phone'] ?? ''));
        $orderLabel = trim((string) ($ctx['order_label'] ?? $ctx['centiiv_order_id'] ?? ''));
        $requested = (float) ($ctx['requested_ngn'] ?? 0);
        $receivedNgn = isset($ctx['received_ngn']) && is_numeric($ctx['received_ngn'])
            ? (float) $ctx['received_ngn']
            : null;
        $userAcct = trim((string) ($ctx['user_account_number'] ?? ''));
        $userCode = trim((string) ($ctx['user_bank_code'] ?? ''));
        if ($phone === '' || $orderLabel === '' || $requested <= 0 || strlen($userAcct) !== 10 || $userCode === '') {
            error_log('SendraIntermediaryDisburse: missing fields order=' . $orderLabel);

            return false;
        }

        $userCode = SendraCentiivRampService::normalizeCentiivBankCode($userCode);

        if ($receivedNgn === null || !SendraCentiivRampService::intermediaryPoolCoversUserPayout($requested, $receivedNgn)) {
            $got = $receivedNgn !== null ? number_format($receivedNgn, 2, '.', ',') : '0.00';
            $need = SendraCentiivRampService::intermediaryPoolMinimumReceivedNgn($requested);
            error_log(
                'SendraIntermediaryDisburse: pool shortfall order=' . $orderLabel
                . ' requested=' . $requested . ' received=' . ($receivedNgn ?? 'null')
                . ' pool_min=' . $need
            );
            // Notify once — offramp poller retries every ~10m and must not re-spam WhatsApp.
            if (SendraCentiivRampService::claimPoolShortfallUserNotify($orderLabel)) {
                $wa->sendMessage(
                    $phone,
                    "⚠️ *Payout delayed*\n\n" .
                    "We received *₦{$got}* in our settlement account but need at least *₦" .
                    number_format($need, 0, '.', ',') . "* before sending *₦" .
                    number_format($requested, 0, '.', ',') . "* to your bank.\n\n" .
                    "Your crypto conversion completed. Contact support@usesendra.com if you need help.\n\n" .
                    "Order: `{$orderLabel}`"
                );
            }

            return false;
        }

        $existing = SendraCentiivRampService::loadIntermediaryDisburseContextForOrder($orderLabel);
        if (is_array($existing) && !empty($existing['disburse_tracking_id'])) {
            return true;
        }

        $trackingId = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffffffffffff)
        );

        $store = array_merge($ctx, [
            'user_phone' => $phone,
            'centiiv_order_id' => $orderLabel,
            'requested_ngn' => $requested,
            'received_ngn' => $receivedNgn,
            'user_account_number' => $userAcct,
            'user_bank_code' => $userCode,
            'disburse_tracking_id' => $trackingId,
        ]);
        SendraCentiivRampService::cacheIntermediaryDisburseContextForOrder($orderLabel, $trackingId, $store);

        $agg = new SendraCentiivRampService();
        $res = $agg->disburseNgnToBank(
            $userAcct,
            $userCode,
            $requested,
            $trackingId,
            'Sendra WhatsApp payout ' . $phone
        );
        if (empty($res['success'])) {
            error_log(
                'SendraIntermediaryDisburse failed order=' . $orderLabel
                . ' err=' . ($res['error'] ?? 'unknown')
            );

            return false;
        }

        self::sendReceipt($wa, $store);

        return true;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function sendReceipt(WhatsAppService $wa, array $ctx): bool
    {
        $phone = trim((string) ($ctx['user_phone'] ?? ''));
        if ($phone === '') {
            return false;
        }
        $requested = (float) ($ctx['requested_ngn'] ?? 0);
        $feeFields = SendraCentiivPayoutReceiptFactory::resolveIntermediaryReceiptFee($requested, $ctx);
        $trackingId = trim((string) ($ctx['disburse_tracking_id'] ?? $ctx['centiiv_order_id'] ?? ''));

        try {
            $model = [
                'amount_ngn' => $requested > 0 ? $requested : null,
                'transaction_fee_ngn' => $feeFields['transaction_fee_ngn'],
                'transaction_fee_crypto' => $feeFields['transaction_fee_crypto'],
                'transaction_fee_asset' => $feeFields['transaction_fee_asset'],
                'account_name' => (string) ($ctx['user_account_name'] ?? '—'),
                'account_number' => (string) ($ctx['user_account_number'] ?? '—'),
                'bank_name' => (string) ($ctx['user_bank_name'] ?? '—'),
                'withdraw_asset' => (string) ($ctx['sendra_ledger_asset'] ?? 'USDC'),
                'withdraw_amount' => is_numeric($ctx['sendra_ledger_amount'] ?? null) ? (float) $ctx['sendra_ledger_amount'] : null,
                'display_reference' => $trackingId !== '' ? $trackingId : '—',
                'transaction_id' => $trackingId !== '' ? $trackingId : null,
                'sender_name' => (string) (
                    $ctx['sender_name']
                    ?? SendraCentiivPayoutReceiptFactory::resolveReceiptSenderLabel(true)
                ),
                'completed_at' => SendraCentiivPayoutReceiptFactory::formatReceiptNow('M j, Y g:i A'),
                'conversion_rate' => SendraCentiivPayoutReceiptFactory::formatIntermediaryReceiptConversionRate($ctx),
            ];
            $path = SendraPayoutReceiptImageService::renderToTempFile($model);
            if ($path !== null && is_readable($path)) {
                $wa->sendImage($phone, $path, '✅ Sendra — bank payout receipt');
                @unlink($path);

                return true;
            }
        } catch (\Throwable $e) {
            error_log('SendraIntermediaryDisburse receipt: ' . $e->getMessage());
        }

        return false;
    }
}
