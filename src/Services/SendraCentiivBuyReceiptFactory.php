<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Display model for Sendra buy (on-ramp) receipts — NGN paid → crypto received.
 */
final class SendraCentiivBuyReceiptFactory
{
    /**
     * @param array<string, mixed> $meta pending_transactions.human_description (onramp_oneclick_centiiv)
     * @param array<string, mixed>|null $oneClickStatusData decoded 1Click /v0/status (optional)
     * @return array<string, mixed>
     */
    public static function buildFromOnrampMeta(array $meta, ?array $oneClickStatusData = null): array
    {
        $orderId = trim((string) ($meta['centiiv_order_id'] ?? ''));
        $payNgn = self::parsePayNgn($meta);
        $asset = strtoupper(trim((string) ($meta['ledger_asset'] ?? 'USDC')));
        if ($asset === '') {
            $asset = 'USDC';
        }

        $receiveAmt = self::parseReceiveAmount($meta, $oneClickStatusData, $asset);
        $bank = self::resolveVirtualBank($meta, $orderId);

        $oneclickRef = trim((string) ($meta['sendra_oneclick_correlation_id'] ?? ''));
        $memo = trim((string) ($meta['sendra_oneclick_deposit_memo'] ?? $meta['centiiv_destination_memo'] ?? ''));

        $rate = '';
        if ($receiveAmt !== null && $receiveAmt > 0 && $payNgn > 0) {
            $rate = '₦' . number_format($payNgn / $receiveAmt, 2, '.', ',');
        }

        $ref = $orderId !== '' ? $orderId : ($oneclickRef !== '' ? $oneclickRef : '—');
        if ($memo !== '' && strlen($memo) <= 24) {
            $ref = $memo;
        }

        return [
            'amount_ngn' => $payNgn > 0 ? $payNgn : null,
            'receive_asset' => $asset,
            'receive_amount' => $receiveAmt,
            'conversion_rate' => $rate,
            'bank_name' => $bank['bank_name'],
            'account_name' => $bank['account_name'],
            'account_number' => $bank['account_number'],
            'display_reference' => $ref,
            'oneclick_reference' => $oneclickRef,
            'centiiv_order_id' => $orderId,
            'completed_at' => SendraCentiivPayoutReceiptFactory::formatReceiptNow('M d, Y g:i A'),
            'status_label' => 'Successful',
            'payment_method' => 'Bank transfer',
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function parsePayNgn(array $meta): float
    {
        if (isset($meta['pay_ngn']) && is_numeric($meta['pay_ngn'])) {
            return (float) $meta['pay_ngn'];
        }
        $disp = (string) ($meta['pay_ngn_display'] ?? '');
        $disp = str_replace([',', '₦', ' '], '', $disp);

        return is_numeric($disp) ? (float) $disp : 0.0;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $oneClickStatusData
     */
    private static function parseReceiveAmount(array $meta, ?array $oneClickStatusData, string $asset): ?float
    {
        if (is_array($oneClickStatusData)) {
            foreach (['amountOut', 'amount_out', 'destinationAmount', 'destination_amount'] as $k) {
                if (isset($oneClickStatusData[$k]) && is_numeric($oneClickStatusData[$k])) {
                    $v = (float) $oneClickStatusData[$k];
                    if ($v > 1_000_000) {
                        $v = (float) SendraOneClickService::smallestUnitsToFloatString((string) (int) $v, 6);
                    }
                    if ($v > 0) {
                        return $v;
                    }
                }
            }
        }

        $disp = trim((string) ($meta['receive_crypto_display'] ?? ''));
        if ($disp !== '' && preg_match('/^([\d.]+)/', $disp, $m)) {
            return (float) $m[1];
        }

        foreach (['receive_crypto', 'ledger_amount', 'amount'] as $k) {
            if (isset($meta[$k]) && is_numeric($meta[$k])) {
                $v = (float) $meta[$k];
                if ($v > 0) {
                    return $v;
                }
            }
        }

        unset($asset);

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{bank_name: string, account_name: string, account_number: string}
     */
    private static function resolveVirtualBank(array $meta, string $orderId): array
    {
        $bank = $meta['virtual_bank'] ?? null;
        if (!is_array($bank) && $orderId !== '') {
            $agg = new SendraCentiivRampService();
            if ($agg->isConfigured()) {
                $req = $agg->getRequest($orderId);
                if (!empty($req['success']) && is_array($req['data'] ?? null)) {
                    $data = $req['data'];
                    if (isset($data['data']) && is_array($data['data'])) {
                        $data = $data['data'];
                    }
                    $parsed = SendraCentiivRampService::extractVirtualBankAccount($data);
                    if ($parsed !== null) {
                        $bank = $parsed;
                    }
                    if (isset($data['amount']) && is_numeric($data['amount']) && empty($meta['pay_ngn'])) {
                        $meta['pay_ngn'] = (float) $data['amount'];
                    }
                }
            }
        }

        if (!is_array($bank)) {
            return [
                'bank_name' => '—',
                'account_name' => '—',
                'account_number' => '—',
            ];
        }

        $code = isset($bank['bank_code']) ? (string) $bank['bank_code'] : null;
        $name = SendraCentiivRampService::displayVirtualBankName(
            (string) ($bank['bank_name'] ?? ''),
            $code
        );

        return [
            'bank_name' => $name !== '' ? $name : '—',
            'account_name' => (string) ($bank['account_name'] ?? '—'),
            'account_number' => (string) ($bank['account_number'] ?? '—'),
        ];
    }
}
