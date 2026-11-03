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

        $centiivCrypto = self::resolveCentiivCryptoForFee($meta, $orderId);
        $rate = self::resolveOfficialConversionRate($meta, $orderId, $payNgn, $receiveAmt, $centiivCrypto, $asset);
        $ngnPerCrypto = ($centiivCrypto !== null && $payNgn > 0)
            ? $payNgn / (float) $centiivCrypto['amount']
            : (($receiveAmt !== null && $receiveAmt > 0 && $payNgn > 0) ? $payNgn / $receiveAmt : null);
        $feeFields = SendraCentiivPayoutReceiptFactory::resolveReceiptTransactionFee(
            array_merge($meta, $centiivCrypto !== null ? [
                'centiiv_crypto_received' => $centiivCrypto['amount'],
                'centiiv_crypto_asset' => $centiivCrypto['asset'],
                'centiiv_usdc' => $centiivCrypto['asset'] === 'USDC' ? $centiivCrypto['amount'] : ($meta['centiiv_usdc'] ?? null),
            ] : []),
            [],
            $ngnPerCrypto
        );

        $ref = $orderId !== '' ? $orderId : ($oneclickRef !== '' ? $oneclickRef : '—');
        if ($memo !== '' && strlen($memo) <= 24) {
            $ref = $memo;
        }

        return [
            'amount_ngn' => $payNgn > 0 ? $payNgn : null,
            'receive_asset' => $asset,
            'receive_amount' => $receiveAmt,
            'conversion_rate' => $rate,
            'transaction_fee_ngn' => $feeFields['transaction_fee_ngn'],
            'transaction_fee_crypto' => $feeFields['transaction_fee_crypto'],
            'transaction_fee_asset' => $feeFields['transaction_fee_asset'],
            'centiiv_crypto_received' => $centiivCrypto['amount'] ?? null,
            'centiiv_crypto_asset' => $centiivCrypto['asset'] ?? null,
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
            $swap = $oneClickStatusData['swapDetails'] ?? null;
            if (is_array($swap)) {
                foreach (['amountOutFormatted', 'amount_out_formatted', 'amountOut', 'amount_out'] as $k) {
                    if (isset($swap[$k]) && is_numeric($swap[$k])) {
                        $v = (float) $swap[$k];
                        if ($v > 1_000_000) {
                            $v = (float) SendraOneClickService::smallestUnitsToFloatString((string) (int) $v, 6);
                        }
                        if ($v > 0) {
                            return $v;
                        }
                    }
                }
            }

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

    /**
     * USDC/USDT amount Centiiv received for fee (0.5% of this value on receipts).
     *
     * @param array<string, mixed> $meta
     * @return array{amount: float, asset: string}|null
     */
    private static function resolveCentiivCryptoForFee(array $meta, string $orderId): ?array
    {
        foreach (['centiiv_crypto_received', 'centiiv_usdc', 'centiiv_usdc_amount', 'receive_crypto'] as $key) {
            if (!isset($meta[$key]) || !is_numeric($meta[$key]) || (float) $meta[$key] <= 0) {
                continue;
            }

            $asset = strtoupper(trim((string) ($meta['centiiv_crypto_asset'] ?? 'USDC')));

            return [
                'amount' => (float) $meta[$key],
                'asset' => $asset !== '' ? $asset : 'USDC',
            ];
        }

        if ($orderId === '') {
            return null;
        }

        $agg = new SendraCentiivRampService();
        if (!$agg->isConfigured()) {
            return null;
        }

        $res = $agg->getRequest($orderId);
        if (empty($res['success']) || !is_array($res['data'] ?? null)) {
            return null;
        }

        $data = $res['data'];
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (!is_array($data)) {
            return null;
        }

        $fromOrder = SendraCentiivPayoutReceiptFactory::resolveCryptoReceivedAtCentiiv($meta, $data);
        if ($fromOrder !== null) {
            return $fromOrder;
        }

        $payNgn = self::parsePayNgn($meta);
        if ($payNgn > 0) {
            $summary = SendraCentiivRampService::summarizeQuoteCrypto($data, $payNgn);
            if ($summary['receive_crypto'] !== null && (float) $summary['receive_crypto'] > 0) {
                return ['amount' => (float) $summary['receive_crypto'], 'asset' => 'USDC'];
            }
        }

        return null;
    }

    /**
     * Official Centiiv buy rate (dashboard quote), not NGN paid ÷ wallet USDC received.
     *
     * @param array<string, mixed> $meta
     * @param array{amount: float, asset: string}|null $centiivCrypto
     */
    private static function resolveOfficialConversionRate(
        array $meta,
        string $orderId,
        float $payNgn,
        ?float $receiveAmt,
        ?array $centiivCrypto,
        string $asset
    ): string {
        $merged = [];
        $quote = null;

        if (isset($meta['daya_rate']) && is_numeric($meta['daya_rate']) && (float) $meta['daya_rate'] > 0) {
            $quote = ['rate' => (float) $meta['daya_rate']];
        } elseif (isset($meta['centiiv_order_rate']) && is_numeric($meta['centiiv_order_rate']) && (float) $meta['centiiv_order_rate'] > 0) {
            $quote = ['rate' => (float) $meta['centiiv_order_rate']];
        } elseif (is_array($meta['quote'] ?? null)) {
            $quote = $meta['quote'];
        }

        if ($quote === null && $orderId !== '') {
            $agg = new SendraCentiivRampService();
            if ($agg->isConfigured()) {
                $res = $agg->getRequest($orderId);
                if (!empty($res['success']) && is_array($res['data'])) {
                    $orderData = $res['data']['data'] ?? $res['data'];
                    if (is_array($orderData)) {
                        $merged = $orderData;
                        $orderMeta = is_array($orderData['metadata'] ?? null) ? $orderData['metadata'] : [];
                        $quote = is_array($orderMeta['quote'] ?? null) ? $orderMeta['quote'] : null;
                    }
                }
            }
        }

        $withdrawAmount = $centiivCrypto !== null
            ? (float) $centiivCrypto['amount']
            : $receiveAmt;

        return SendraCentiivPayoutReceiptFactory::formatConversionRateDisplay(
            $payNgn > 0 ? $payNgn : null,
            $withdrawAmount,
            $asset,
            $merged,
            $quote
        );
    }
}
