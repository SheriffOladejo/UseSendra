<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Builds a display model for Sendra × Centiiv bank payout receipts (webhook + optional GET /requests/{id}).
 */
final class SendraCentiivPayoutReceiptFactory
{
    /** Send-naira (intermediary pool) bank sender on receipts. */
    private const INTERMEDIARY_SENDER_NAME = 'UseSendra LTD/Centiiv';

    /** Centiiv crypto sell off-ramp (direct to user bank) sender when API omits lp/sender. */
    private const OFFRAMP_SENDER_NAME = 'UseSendra LTD/Centiiv';

    /** Daya crypto sell off-ramp sender when Daya payload omits provider/sender. */
    private const DAYA_OFFRAMP_SENDER_NAME = 'UseSendra LTD/Daya';

    /** Centiiv dashboard transaction fee — 0.5% of crypto received at Centiiv. */
    private const DEFAULT_CENTIIV_FEE_BPS = 50;
    /**
     * Centiiv sends UTC (`…Z`). Receipts default to Africa/Lagos (WAT, UTC+1). Override with SENDRA_RECEIPT_TIMEZONE.
     */
    public static function receiptDisplayTimezone(): \DateTimeZone
    {
        static $cached = null;
        if ($cached instanceof \DateTimeZone) {
            return $cached;
        }
        $name = trim((string) ($_ENV['SENDRA_RECEIPT_TIMEZONE'] ?? getenv('SENDRA_RECEIPT_TIMEZONE') ?: ''));
        if ($name === '') {
            $name = 'Africa/Lagos';
        }
        try {
            $cached = new \DateTimeZone($name);
        } catch (\Throwable $e) {
            $cached = new \DateTimeZone('Africa/Lagos');
        }

        return $cached;
    }

    public static function formatReceiptNow(string $pattern = 'M j, Y g:i A'): string
    {
        return (new \DateTimeImmutable('now', self::receiptDisplayTimezone()))->format($pattern);
    }

    /**
     * User-requested wallet debit for receipts (e.g. 2 USDT), not Centiiv's post-1Click USDC amount.
     *
     * @param array<string, mixed> $meta pending_transactions.human_description and/or Centiiv metadata
     * @param ?string $pendingAmount pending_transactions.amount
     * @param ?string $pendingCurrency pending_transactions.currency
     * @return array{asset: string, amount: ?float}
     */
    public static function resolveUserSellLedger(
        array $meta,
        ?string $pendingAmount = null,
        ?string $pendingCurrency = null
    ): array {
        $sellCtx = is_array($meta['sell_ctx'] ?? null) ? $meta['sell_ctx'] : [];

        $asset = strtoupper(trim((string) (self::firstNonEmptyString([
            is_string($meta['sendra_ledger_asset'] ?? null) ? (string) $meta['sendra_ledger_asset'] : null,
            is_string($sellCtx['asset'] ?? null) ? (string) $sellCtx['asset'] : null,
            $pendingCurrency,
        ]) ?? 'USDT')));

        $amtRaw = self::firstNonEmptyString([
            is_string($meta['sendra_ledger_amount'] ?? null) ? (string) $meta['sendra_ledger_amount'] : null,
            is_string($sellCtx['crypto_amount'] ?? null) ? (string) $sellCtx['crypto_amount'] : null,
            $pendingAmount,
        ]);

        return [
            'asset' => $asset !== '' ? $asset : 'USDT',
            'amount' => is_numeric($amtRaw ?? null) ? (float) $amtRaw : null,
        ];
    }

    /**
     * Wallet debit shown on receipts: the user-requested USDT/USDC, not the Centiiv order USDC
     * amount after a 1Click bridge haircut.
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $norm
     * @return array{0: string, 1: ?float}
     */
    private static function resolveLedgerWithdrawFields(array $meta, array $merged, array $norm): array
    {
        $fromNorm = self::resolveUserSellLedger([
            'sendra_ledger_asset' => $norm['ledger_asset'] ?? null,
            'sendra_ledger_amount' => $norm['ledger_amount'] ?? null,
        ]);
        if ($fromNorm['amount'] !== null) {
            return [$fromNorm['asset'], $fromNorm['amount']];
        }

        $fromMeta = self::resolveUserSellLedger($meta);
        if ($fromMeta['amount'] !== null) {
            return [$fromMeta['asset'], $fromMeta['amount']];
        }

        return [$fromMeta['asset'], null];
    }

    /** Transaction-fee row on payout receipts (on by default). Set SENDRA_RECEIPT_SHOW_TRANSACTION_FEE=0 to hide. */
    public static function showTransactionFeeOnReceipt(): bool
    {
        $raw = $_ENV['SENDRA_RECEIPT_SHOW_TRANSACTION_FEE'] ?? getenv('SENDRA_RECEIPT_SHOW_TRANSACTION_FEE');
        if ($raw === false || $raw === null || (is_string($raw) && trim($raw) === '')) {
            return true;
        }
        $v = is_string($raw) ? strtolower(trim($raw)) : '';

        if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return true;
    }

    public static function centiivTransactionFeeBps(): int
    {
        $bps = (int) ($_ENV['SENDRA_CENTIIV_TRANSACTION_FEE_BPS'] ?? getenv('SENDRA_CENTIIV_TRANSACTION_FEE_BPS') ?: self::DEFAULT_CENTIIV_FEE_BPS);

        return max(0, min(1000, $bps));
    }

    /**
     * Crypto amount that actually reached Centiiv (not the user-requested wallet debit).
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $merged
     * @return array{amount: float, asset: string}|null
     */
    public static function resolveCryptoReceivedAtCentiiv(array $meta, array $merged = []): ?array
    {
        $fromAsset = strtoupper(trim((string) self::firstNonEmptyString([
            is_string($merged['fromAsset'] ?? null) ? (string) $merged['fromAsset'] : null,
            is_string($merged['from_asset'] ?? null) ? (string) $merged['from_asset'] : null,
        ]) ?? ''));
        $orderAmt = $merged['amount'] ?? null;
        if ($fromAsset !== '' && $fromAsset !== 'NGN' && is_numeric($orderAmt) && (float) $orderAmt > 0) {
            return ['amount' => (float) $orderAmt, 'asset' => $fromAsset];
        }

        $onrampCrypto = SendraCentiivRampService::extractOnrampCryptoAtCentiiv($merged);
        if ($onrampCrypto !== null) {
            return $onrampCrypto;
        }

        foreach (['centiiv_crypto_received', 'centiiv_usdc_amount', 'centiiv_usdc'] as $k) {
            if (!isset($meta[$k]) || !is_numeric($meta[$k]) || (float) $meta[$k] <= 0) {
                continue;
            }

            $asset = strtoupper(trim((string) ($meta['centiiv_crypto_asset'] ?? 'USDC')));
            if ($asset === '') {
                $asset = 'USDC';
            }

            return ['amount' => (float) $meta[$k], 'asset' => $asset];
        }

        $depositAmt = self::firstNonEmptyString([
            $meta['depositAmount'] ?? null,
            $meta['deposit_amount'] ?? null,
        ]);
        if ($depositAmt !== null && is_numeric($depositAmt) && (float) $depositAmt > 0) {
            $asset = strtoupper(trim((string) ($meta['depositAsset'] ?? $meta['deposit_asset'] ?? 'USDC')));

            return ['amount' => (float) $depositAmt, 'asset' => $asset !== '' ? $asset : 'USDC'];
        }

        return null;
    }

    /**
     * 0.5% (configurable) of crypto received at Centiiv.
     *
     * @param array{amount: float, asset: string}|null $cryptoAtCentiiv
     * @return array{transaction_fee_crypto: ?float, transaction_fee_asset: ?string, transaction_fee_ngn: ?float}
     */
    public static function computeCentiivTransactionFee(?array $cryptoAtCentiiv, ?float $ngnPerCryptoUnit = null): array
    {
        $empty = [
            'transaction_fee_crypto' => null,
            'transaction_fee_asset' => null,
            'transaction_fee_ngn' => null,
        ];
        if ($cryptoAtCentiiv === null || ($cryptoAtCentiiv['amount'] ?? 0) <= 0) {
            return $empty;
        }

        $bps = self::centiivTransactionFeeBps();
        if ($bps <= 0) {
            return $empty;
        }

        $feeCrypto = round((float) $cryptoAtCentiiv['amount'] * $bps / 10000, 8);
        if ($feeCrypto <= 0) {
            return $empty;
        }

        $asset = strtoupper(trim((string) ($cryptoAtCentiiv['asset'] ?? 'USDC')));
        $feeNgn = null;
        if ($ngnPerCryptoUnit !== null && $ngnPerCryptoUnit > 0) {
            $feeNgn = round($feeCrypto * $ngnPerCryptoUnit, 2);
        }

        return [
            'transaction_fee_crypto' => $feeCrypto,
            'transaction_fee_asset' => $asset !== '' ? $asset : 'USDC',
            'transaction_fee_ngn' => $feeNgn,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $merged
     * @return array{transaction_fee_crypto: ?float, transaction_fee_asset: ?string, transaction_fee_ngn: ?float}
     */
    public static function resolveReceiptTransactionFee(array $meta, array $merged = [], ?float $ngnPerCryptoUnit = null): array
    {
        if (isset($meta['sendra_transaction_fee_crypto']) && is_numeric($meta['sendra_transaction_fee_crypto'])) {
            return [
                'transaction_fee_crypto' => round((float) $meta['sendra_transaction_fee_crypto'], 8),
                'transaction_fee_asset' => strtoupper(trim((string) ($meta['sendra_transaction_fee_asset'] ?? 'USDC'))) ?: 'USDC',
                'transaction_fee_ngn' => isset($meta['sendra_transaction_fee_ngn']) && is_numeric($meta['sendra_transaction_fee_ngn'])
                    ? round((float) $meta['sendra_transaction_fee_ngn'], 2) : null,
            ];
        }

        $cryptoAtCentiiv = self::resolveCryptoReceivedAtCentiiv($meta, $merged);

        return self::computeCentiivTransactionFee($cryptoAtCentiiv, $ngnPerCryptoUnit);
    }

    /**
     * NGN equivalent of the transaction fee for receipt display.
     *
     * @param array<string, mixed> $model
     */
    public static function resolveTransactionFeeNgnForDisplay(array $model): ?float
    {
        $feeNgn = $model['transaction_fee_ngn'] ?? null;
        if (is_numeric($feeNgn) && (float) $feeNgn > 0) {
            return round((float) $feeNgn, 2);
        }

        $feeCrypto = $model['transaction_fee_crypto'] ?? null;
        if (!is_numeric($feeCrypto) || (float) $feeCrypto <= 0) {
            return null;
        }

        $ngnPerCrypto = self::resolveNgnPerCryptoFromReceiptModel($model);
        if ($ngnPerCrypto === null || $ngnPerCrypto <= 0) {
            return null;
        }

        return round((float) $feeCrypto * $ngnPerCrypto, 2);
    }

    /**
     * Maps a Centiiv toAsset (e.g. "XOF", "NGN", "GHS") to its display symbol.
     */
    public static function fiatCurrencySymbol(string $currency): string
    {
        return match (strtoupper(trim($currency))) {
            'NGN'   => '₦',
            'XOF', 'XAF' => 'CFA',
            'GHS'   => 'GH₵',
            'KES'   => 'KSh',
            'UGX'   => 'USh',
            'ETB'   => 'Br',
            'TZS'   => 'TSh',
            'RWF'   => 'RF',
            'MZN'   => 'MT',
            'ZAR'   => 'R',
            'MAD'   => 'MAD',
            'EGP'   => 'E£',
            default => strtoupper(trim($currency)),
        };
    }

    /**
     * Returns the ISO 4217 code for the local fiat on a receipt model.
     *
     * @param array<string, mixed> $model
     */
    public static function receiptFiatCurrency(array $model): string
    {
        $c = strtoupper(trim((string) ($model['fiat_currency'] ?? '')));

        return $c !== '' ? $c : 'NGN';
    }

    /**
     * @param array<string, mixed> $model
     */
    public static function formatNgnReceiptAmount(float $ngn): string
    {
        return '₦' . number_format(
            $ngn,
            abs($ngn - round($ngn)) < 0.005 ? 0 : 2,
            '.',
            ','
        );
    }

    /**
     * Formats a fiat amount for display with the correct currency symbol.
     * For XOF/XAF the symbol is a suffix ("683 CFA"), for others it's a prefix.
     */
    public static function formatFiatReceiptAmount(float $amount, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $symbol   = self::fiatCurrencySymbol($currency);
        $decimals = in_array($currency, ['XOF', 'XAF', 'UGX', 'RWF', 'TZS', 'ETB'], true) ? 0 : 2;
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : $decimals;
        $formatted = number_format($amount, $decimals, '.', ',');
        // CFA and multi-char codes go as suffix for readability; single-char symbols are prefix.
        if (strlen($symbol) > 1 && !in_array($symbol[0], ['₦', '₵', 'E', 'R', 'M', 'T', 'B', 'U'], true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . ' ' . $formatted;
    }

    /**
     * Human-readable transaction fee for receipt templates (local fiat currency).
     *
     * @param array<string, mixed> $model
     */
    public static function formatTransactionFeeDisplay(array $model): ?string
    {
        if (!self::showTransactionFeeOnReceipt()) {
            return null;
        }

        $feeNgn = self::resolveTransactionFeeNgnForDisplay($model);
        if ($feeNgn !== null && $feeNgn > 0) {
            $currency = self::receiptFiatCurrency($model);

            return self::formatFiatReceiptAmount($feeNgn, $currency);
        }

        $feeCrypto = $model['transaction_fee_crypto'] ?? null;
        $feeAsset = strtoupper(trim((string) ($model['transaction_fee_asset'] ?? '')));
        if (is_numeric($feeCrypto) && (float) $feeCrypto > 0 && $feeAsset !== '') {
            $s = rtrim(rtrim(number_format((float) $feeCrypto, 6, '.', ''), '0'), '.');

            return ($s !== '' ? $s : '0') . ' ' . $feeAsset;
        }

        return null;
    }

    /**
     * NGN per 1 unit of crypto at Centiiv (for fee local-currency display).
     *
     * @param array<string, mixed> $model
     */
    private static function resolveNgnPerCryptoFromReceiptModel(array $model): ?float
    {
        $amountNgn = $model['amount_ngn'] ?? null;
        if (!is_numeric($amountNgn) || (float) $amountNgn <= 0) {
            return null;
        }

        foreach (['centiiv_crypto_received', 'centiiv_usdc_amount', 'centiiv_usdc'] as $key) {
            if (isset($model[$key]) && is_numeric($model[$key]) && (float) $model[$key] > 0) {
                return (float) $amountNgn / (float) $model[$key];
            }
        }

        $receiveAmt = $model['receive_amount'] ?? null;
        if (is_numeric($receiveAmt) && (float) $receiveAmt > 0) {
            return (float) $amountNgn / (float) $receiveAmt;
        }

        $withdrawAmt = $model['withdraw_amount'] ?? null;
        if (is_numeric($withdrawAmt) && (float) $withdrawAmt > 0) {
            return (float) $amountNgn / (float) $withdrawAmt;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     * @param array{user_phone: ?string, status: string, order_label: string, oneclick_correlation_id: string} $norm
     * @return array<string, mixed> Model for {@see SendraPayoutReceiptImageService::renderToTempFile()}
     */
    public static function build(array $event, array $norm, ?string $storedPayoutReference = null): array
    {
        $eventData = self::coerceArr($event['data'] ?? null);

        $agg = new SendraCentiivRampService();
        $orderId = trim((string) ($norm['order_label'] ?? ''));
        if ($orderId === '' || strtolower($orderId) === 'order') {
            $orderId = '';
        }

        $merged = $eventData;
        if ($agg->isConfigured() && $orderId !== '') {
            $req = $agg->getRequest($orderId);
            if ($req['success']) {
                $apiSlice = self::unwrapCentiivPayload($req['data']);
                $merged = self::mergeReceiptOrderPayload($eventData, $apiSlice);
            }
        }

        $meta = self::coerceArr($merged['metadata'] ?? null);
        if ($meta === []) {
            $meta = self::coerceArr($eventData['metadata'] ?? null);
        }
        $meta = self::flattenCentiivMetadata($meta);

        $beneficiary = self::coerceArr($merged['beneficiary'] ?? null);
        $benDetails = self::coerceArr($beneficiary['details'] ?? null);
        if ($benDetails !== []) {
            $beneficiary = array_merge($beneficiary, [
                'accountName' => $benDetails['accountName'] ?? $benDetails['account_name'] ?? $beneficiary['accountName'] ?? null,
                'accountNumber' => $benDetails['accountNumber'] ?? $benDetails['account_number'] ?? $beneficiary['accountNumber'] ?? null,
                'bankName' => $benDetails['bankName'] ?? $benDetails['bank_name'] ?? $beneficiary['bankName'] ?? null,
                'bankCode' => $benDetails['bankCode'] ?? $benDetails['bank_code'] ?? $beneficiary['bankCode'] ?? null,
            ]);
        }
        $benExternalId = self::firstNonEmptyString([
            $beneficiary['externalId'] ?? null,
            $beneficiary['external_id'] ?? null,
        ]);
        $isIntermediaryPoolReceipt = $benExternalId === 'sendra_intermediary_ngn';
        $disbRec = self::coerceArr($merged['recipient'] ?? null);
        if ($disbRec !== [] && ($beneficiary === [] || ($beneficiary['accountName'] ?? $beneficiary['account_name'] ?? '') === '')) {
            $beneficiary = array_merge($beneficiary, [
                'accountName' => $disbRec['accountName'] ?? $disbRec['account_name'] ?? $disbRec['name'] ?? null,
                'bankName' => $disbRec['bankName'] ?? $disbRec['bank_name'] ?? null,
                'accountNumber' => $disbRec['accountNumber'] ?? $disbRec['account_number'] ?? null,
            ]);
        }
        $recipient = self::firstNonEmptyString([
            $beneficiary['accountName'] ?? null,
            $beneficiary['account_name'] ?? null,
            $beneficiary['name'] ?? null,
            $merged['accountName'] ?? null,
            $merged['payeeName'] ?? null,
            $merged['payee_name'] ?? null,
        ]) ?? '—';

        $bankName = self::firstNonEmptyString([
            $beneficiary['bankName'] ?? null,
            $beneficiary['bank_name'] ?? null,
            $beneficiary['bank'] ?? null,
            $merged['bankName'] ?? null,
            $merged['bank_name'] ?? null,
        ]) ?? '';

        $acct = self::firstNonEmptyString([
            $beneficiary['accountNumber'] ?? null,
            $beneficiary['account_number'] ?? null,
            $beneficiary['nuban'] ?? null,
            $merged['accountNumber'] ?? null,
            $merged['account_number'] ?? null,
        ]) ?? '—';

        $ful0 = isset($merged['fulfillments'][0]) && is_array($merged['fulfillments'][0]) ? $merged['fulfillments'][0] : [];
        if ($ful0 !== []) {
            if (($recipient === '' || $recipient === '—')) {
                $recipient = self::firstNonEmptyString([$ful0['accountName'] ?? null, $ful0['account_name'] ?? null]) ?? $recipient;
            }
            if ($bankName === '') {
                $bankName = self::firstNonEmptyString([$ful0['bankName'] ?? null, $ful0['bank_name'] ?? null]) ?? '';
            }
            if (($acct === '' || $acct === '—')) {
                $acct = self::firstNonEmptyString([$ful0['accountNumber'] ?? null, $ful0['account_number'] ?? null]) ?? $acct;
            }
        }

        $bankCodeForLookup = self::firstNonEmptyString([
            $beneficiary['bankCode'] ?? null,
            $beneficiary['bank_code'] ?? null,
            $ful0['bankCode'] ?? null,
            $ful0['bank_code'] ?? null,
        ]);
        if ($bankName === '' && $bankCodeForLookup !== null && $agg->isConfigured()) {
            $resolvedName = self::resolveBankNameFromCode($agg, $bankCodeForLookup);
            if ($resolvedName !== null && $resolvedName !== '') {
                $bankName = $resolvedName;
            }
        }
        if ($bankName === '' && $bankCodeForLookup !== null) {
            $fb = SendraCentiivRampService::fallbackNipBankDisplayName($bankCodeForLookup);
            if ($fb !== null && $fb !== '') {
                $bankName = $fb;
            }
        }
        if ($bankName === '' && $bankCodeForLookup !== null) {
            $bankName = 'Bank code ' . SendraCentiivRampService::normalizeCentiivBankCode($bankCodeForLookup);
        }

        $bankLine = $bankName !== '' ? ($bankName . '  |  ' . $acct) : $acct;

        $fulfillmentNgn = self::extractNgnAmount($merged, $meta);
        $amount = $fulfillmentNgn;
        $requestedNgn = null;
        if (isset($meta['sendra_requested_ngn']) && is_numeric($meta['sendra_requested_ngn'])) {
            $requestedNgn = round((float) $meta['sendra_requested_ngn'], 2);
            if ($requestedNgn > 0) {
                $amount = $requestedNgn;
            }
        }
        if ($isIntermediaryPoolReceipt) {
            if ($requestedNgn === null || $requestedNgn <= 0) {
                throw new \RuntimeException(
                    'Intermediary Centiiv order receipt must use user bank disburse context, not pool fulfillment'
                );
            }
            $userAcct = trim((string) ($meta['sendra_user_account_number'] ?? ''));
            $userName = trim((string) ($meta['sendra_user_account_name'] ?? ''));
            $userBank = trim((string) ($meta['sendra_user_bank_name'] ?? ''));
            if ($userAcct !== '' && strlen($userAcct) === 10) {
                $acct = $userAcct;
            }
            if ($userName !== '') {
                $recipient = $userName;
            }
            if ($userBank !== '') {
                $bankName = $userBank;
                $bankLine = $userBank . '  |  ' . $acct;
            }
            $amount = $requestedNgn;
        }

        $transactionFeeNgn = null;
        $transactionFeeCrypto = null;
        $transactionFeeAsset = null;
        $cryptoAtCentiiv = self::resolveCryptoReceivedAtCentiiv($meta, $merged);
        $ngnPerCrypto = null;
        if ($cryptoAtCentiiv !== null && $amount !== null && $amount > 0) {
            $ngnPerCrypto = (float) $amount / (float) $cryptoAtCentiiv['amount'];
        }
        $feeFields = self::resolveReceiptTransactionFee($meta, $merged, $ngnPerCrypto);
        $transactionFeeNgn = $feeFields['transaction_fee_ngn'];
        $transactionFeeCrypto = $feeFields['transaction_fee_crypto'];
        $transactionFeeAsset = $feeFields['transaction_fee_asset'];

        $txId = self::firstNonEmptyString([
            $ful0['disbursalRef'] ?? null,
            $ful0['disbursalSessionId'] ?? null,
            $merged['transactionId'] ?? null,
            $merged['transaction_id'] ?? null,
            $merged['entryId'] ?? null,
            $merged['entry_id'] ?? null,
            $meta['trackingId'] ?? null,
            $merged['id'] ?? null,
            $merged['requestId'] ?? null,
            $merged['request_id'] ?? null,
            $merged['orderId'] ?? null,
            $merged['order_id'] ?? null,
            $norm['order_label'] ?? null,
        ]) ?? '—';

        $completed = self::firstNonEmptyString([
            $merged['completedAt'] ?? null,
            $merged['completed_at'] ?? null,
            $merged['updatedAt'] ?? null,
            $merged['updated_at'] ?? null,
            $merged['fulfilledAt'] ?? null,
            $merged['fulfilled_at'] ?? null,
            is_string($event['timestamp'] ?? null) ? $event['timestamp'] : null,
        ]);

        $payoutPartner = self::resolvePayoutPartner($merged, $meta, $ful0);
        $oneclick = trim((string) ($norm['oneclick_correlation_id'] ?? ''));
        $bridgeNote = $oneclick !== '' ? ('Crypto bridge (1Click): ' . $oneclick) : '';

        $sessionId = self::firstNonEmptyString([
            $ful0['disbursalSessionId'] ?? null,
            $ful0['disbursal_session_id'] ?? null,
            $meta['sessionId'] ?? null,
        ]);

        $createdRaw = self::firstNonEmptyString([$merged['createdAt'] ?? null, $merged['created_at'] ?? null]);
        $updatedRaw = self::firstNonEmptyString([$merged['updatedAt'] ?? null, $merged['updated_at'] ?? null]);
        $finishedRaw = self::firstNonEmptyString([
            $merged['finishedAt'] ?? null,
            $merged['finished_at'] ?? null,
            $merged['completedAt'] ?? null,
            is_string($event['timestamp'] ?? null) ? $event['timestamp'] : null,
        ]);

        // User-requested wallet debit (e.g. 1 USDT), not the Centiiv order USDC amount after 1Click.
        [$ledgerAsset, $ledgerAmt] = self::resolveLedgerWithdrawFields($meta, $merged, $norm);

        // Resolve the local fiat currency from the Centiiv order (toAsset). Falls back to NGN.
        $toAsset = strtoupper(trim((string) ($merged['toAsset'] ?? $merged['to_asset'] ?? '')));
        $fiatCurrency = in_array($toAsset, [
            'NGN', 'XOF', 'XAF', 'GHS', 'KES', 'UGX', 'TZS', 'ETB', 'RWF', 'MZN', 'ZAR', 'MAD', 'EGP',
        ], true) ? $toAsset : 'NGN';
        $fiatSymbol = self::fiatCurrencySymbol($fiatCurrency);

        $quote = is_array($meta['quote'] ?? null) ? $meta['quote'] : null;
        $conversionRate = self::resolveConversionRateDisplay(
            $amount,
            $ledgerAmt,
            strtoupper(trim((string) $ledgerAsset)),
            $merged,
            $quote,
            $fiatCurrency
        );

        $displayReference = self::formatBankTransferReferenceForDisplay(
            self::resolveDisplayReference($merged, $meta, $ful0, $txId, $storedPayoutReference)
        );

        return [
            // Legacy fields (used by older receipt design)
            'recipient' => $recipient,
            'bank_line' => $bankLine,

            // New receipt design fields
            'bank_name' => $bankName !== '' ? $bankName : '—',
            'account_name' => $recipient,
            'account_number' => $acct,
            'sender_name' => self::resolveReceiptSenderLabel($isIntermediaryPoolReceipt, $merged, $meta, $ful0),
            'withdraw_asset' => strtoupper(trim((string) $ledgerAsset)),
            'withdraw_amount' => $ledgerAmt,
            'conversion_rate' => $conversionRate,

            // Fiat currency on this payout (XOF for Togo/Benin, NGN for Nigeria, etc.)
            'fiat_currency' => $fiatCurrency,
            'fiat_symbol'   => $fiatSymbol,

            'amount_ngn' => $amount,   // Kept as-is for back-compat; holds the local fiat amount regardless of currency
            'transaction_fee_ngn' => $transactionFeeNgn,
            'transaction_fee_crypto' => $transactionFeeCrypto,
            'transaction_fee_asset' => $transactionFeeAsset,
            'centiiv_crypto_received' => $cryptoAtCentiiv['amount'] ?? null,
            'transaction_id' => $txId,
            /** Matches Opay/bank Remark (Nomba disbursalRef) when available. */
            'display_reference' => $displayReference,
            'bank_remark' => self::resolveBankRemark($merged, $meta, $ful0),
            'completed_at' => self::formatWhen($completed),
            'status_label' => 'Successful',
            'payout_partner' => $payoutPartner,
            'bridge_note' => $bridgeNote,
            'session_id' => $sessionId ?? '',
            'timeline' => [
                ['label' => 'Payment successful', 'time' => self::formatTimelineShort($createdRaw)],
                ['label' => 'Processing by bank', 'time' => self::formatTimelineShort($updatedRaw ?: $createdRaw)],
                ['label' => 'Received by bank', 'time' => self::formatTimelineShort($finishedRaw ?: $updatedRaw ?: $createdRaw)],
            ],
            'payment_method' => 'Wallet',
        ];
    }

    private static function formatTimelineShort(?string $iso): string
    {
        return self::formatIsoInReceiptTz($iso, 'm-d g:i:s A');
    }

    /**
     * NGN per 1 unit of crypto (e.g. ₦1,374.52) for the receipt summary row.
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed>|null $quote
     */
    public static function formatConversionRateDisplay(
        ?float $amountNgn,
        ?float $withdrawAmount,
        string $asset,
        array $merged = [],
        ?array $quote = null
    ): string {
        return self::resolveConversionRateDisplay($amountNgn, $withdrawAmount, $asset, $merged, $quote);
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed>|null $quote
     * @param string $fiatCurrency ISO-4217 code of the local fiat (default: NGN)
     */
    private static function resolveConversionRateDisplay(
        ?float $amountNgn,
        ?float $withdrawAmount,
        string $asset,
        array $merged,
        ?array $quote,
        string $fiatCurrency = 'NGN'
    ): string {
        if ($asset === '') {
            $asset = 'USDC';
        }
        if ($fiatCurrency === '') {
            $fiatCurrency = 'NGN';
        }

        $rate = null;
        if (is_array($quote)) {
            foreach (['rate', 'effectiveRate', 'finalRate', 'exchangeRate', 'fxRate'] as $k) {
                if (isset($quote[$k]) && is_numeric($quote[$k]) && (float) $quote[$k] > 0) {
                    $rate = (float) $quote[$k];
                    break;
                }
            }
        }

        if ($rate === null) {
            $rate = SendraCentiivRampService::extractOfficialNgnRateFromOrder($merged);
        }

        if ($rate === null) {
            $summary = SendraCentiivRampService::summarizeQuoteNgn($merged, $withdrawAmount);
            if ($amountNgn !== null && $amountNgn > 0 && $withdrawAmount !== null && $withdrawAmount > 0) {
                $rate = $amountNgn / $withdrawAmount;
            } elseif ($summary['receive_ngn'] !== null && $withdrawAmount !== null && $withdrawAmount > 0) {
                $rate = (float) $summary['receive_ngn'] / $withdrawAmount;
            }
        }

        if ($rate === null || $rate <= 0) {
            return '—';
        }

        $symbol = self::fiatCurrencySymbol($fiatCurrency);
        $decimals = in_array($fiatCurrency, ['XOF', 'XAF', 'UGX', 'RWF', 'TZS', 'ETB'], true) ? 0 : 2;
        $formatted = number_format($rate, $decimals, '.', ',');
        if (strlen($symbol) > 1 && !in_array($symbol[0], ['₦', '₵', 'E', 'R', 'M', 'T', 'B', 'U'], true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    /**
     * Official Centiiv conversion rate for intermediary payout receipts (from order metadata, not payout÷USDT).
     *
     * @param array<string, mixed> $ctx
     */
    public static function formatIntermediaryReceiptConversionRate(array $ctx): string
    {
        $requested = (float) ($ctx['requested_ngn'] ?? 0);
        $withdrawAmount = isset($ctx['sendra_ledger_amount']) && is_numeric($ctx['sendra_ledger_amount'])
            ? (float) $ctx['sendra_ledger_amount']
            : null;
        $asset = strtoupper(trim((string) ($ctx['sendra_ledger_asset'] ?? 'USDT')));

        $merged = [];
        $quote = null;
        if (isset($ctx['daya_rate']) && is_numeric($ctx['daya_rate']) && (float) $ctx['daya_rate'] > 0) {
            $quote = ['rate' => (float) $ctx['daya_rate']];
        } elseif (isset($ctx['centiiv_order_rate']) && is_numeric($ctx['centiiv_order_rate']) && (float) $ctx['centiiv_order_rate'] > 0) {
            $quote = ['rate' => (float) $ctx['centiiv_order_rate']];
        } elseif (!empty($ctx['centiiv_order_id'])) {
            $agg = new SendraCentiivRampService();
            if ($agg->isConfigured()) {
                $res = $agg->getRequest((string) $ctx['centiiv_order_id']);
                if (!empty($res['success']) && is_array($res['data'])) {
                    $orderData = $res['data']['data'] ?? $res['data'];
                    if (is_array($orderData)) {
                        $merged = $orderData;
                        $meta = is_array($orderData['metadata'] ?? null) ? $orderData['metadata'] : [];
                        $quote = is_array($meta['quote'] ?? null) ? $meta['quote'] : null;
                    }
                }
            }
        }

        return self::formatConversionRateDisplay(
            $requested > 0 ? $requested : null,
            $withdrawAmount,
            $asset,
            $merged,
            $quote
        );
    }

    /**
     * Transaction fee on intermediary receipts — 0.5% of crypto received at Centiiv.
     *
     * @param array<string, mixed> $ctx
     * @return array{transaction_fee_crypto: ?float, transaction_fee_asset: ?string, transaction_fee_ngn: ?float}
     */
    public static function resolveIntermediaryReceiptFee(float $requestedNgn, array $ctx): array
    {
        $meta = [
            'centiiv_crypto_received' => $ctx['centiiv_crypto_received'] ?? null,
            'centiiv_crypto_asset' => $ctx['centiiv_crypto_asset'] ?? ($ctx['sendra_ledger_asset'] ?? 'USDT'),
        ];
        $ngnPerCrypto = null;
        if (isset($meta['centiiv_crypto_received']) && is_numeric($meta['centiiv_crypto_received']) && (float) $meta['centiiv_crypto_received'] > 0 && $requestedNgn > 0) {
            $ngnPerCrypto = $requestedNgn / (float) $meta['centiiv_crypto_received'];
        }

        return self::resolveReceiptTransactionFee($meta, [], $ngnPerCrypto);
    }

    /**
     * @deprecated Use {@see resolveIntermediaryReceiptFee()} — kept for scripts that expect NGN only.
     * @param array<string, mixed> $ctx
     */
    public static function resolveIntermediaryReceiptFeeNgn(float $requestedNgn, array $ctx): ?float
    {
        $fee = self::resolveIntermediaryReceiptFee($requestedNgn, $ctx);

        return $fee['transaction_fee_ngn'];
    }

    private static function resolveBankNameFromCode(SendraCentiivRampService $agg, string $bankCode): ?string
    {
        $norm = SendraCentiivRampService::normalizeCentiivBankCode($bankCode);
        if ($norm === '') {
            return null;
        }
        $res = $agg->getBanks();
        if (!$res['success']) {
            return null;
        }
        $row = SendraCentiivRampService::findRawBankRowInBanksResponse($res['data'], $norm);
        if ($row === null) {
            return null;
        }

        return self::firstNonEmptyString([
            $row['name'] ?? null,
            $row['bankName'] ?? null,
            $row['bank_name'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $decoded Top-level JSON from Centiiv GET
     * @return array<string, mixed>
     */
    /**
     * Centiiv GET /requests often omits bank remark / API-TRANSFER ids present on webhooks.
     *
     * @param array<string, mixed> $eventData
     * @param array<string, mixed> $apiSlice
     * @return array<string, mixed>
     */
    private static function mergeReceiptOrderPayload(array $eventData, array $apiSlice): array
    {
        $merged = array_merge($eventData, $apiSlice);

        $eventFul = is_array($eventData['fulfillments'][0] ?? null) ? $eventData['fulfillments'][0] : [];
        $apiFul = is_array($merged['fulfillments'][0] ?? null) ? $merged['fulfillments'][0] : [];
        if ($eventFul !== []) {
            $overlay = [];
            foreach ([
                'remark',
                'narration',
                'bankRemark',
                'bank_remark',
                'transferRemark',
                'paymentRemark',
                'description',
                'paymentDescription',
                'payment_description',
                'disbursalRef',
                'disbursal_ref',
                'disbursalSessionId',
                'disbursal_session_id',
                'lpName',
                'providerName',
            ] as $k) {
                $v = $eventFul[$k] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    $overlay[$k] = trim($v);
                }
            }
            foreach ($eventFul as $v) {
                if (is_string($v) && stripos($v, 'API-TRANSFER') !== false) {
                    $overlay['remark'] = trim($v);
                    break;
                }
            }
            if ($overlay !== []) {
                $merged['fulfillments'] = $merged['fulfillments'] ?? [];
                if (!is_array($merged['fulfillments'])) {
                    $merged['fulfillments'] = [];
                }
                $merged['fulfillments'][0] = array_merge($apiFul, $overlay);
            }
        }

        $eventMeta = is_array($eventData['metadata'] ?? null) ? $eventData['metadata'] : [];
        $mergedMeta = is_array($merged['metadata'] ?? null) ? $merged['metadata'] : [];
        if ($eventMeta !== []) {
            $metaOverlay = [];
            foreach ($eventMeta as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                if (is_string($v) && (stripos($v, 'API-TRANSFER') !== false || str_starts_with($k, 'sendra_'))) {
                    $metaOverlay[$k] = $v;
                }
            }
            if ($metaOverlay !== []) {
                $merged['metadata'] = array_merge($mergedMeta, $metaOverlay);
            }
        }

        foreach ($eventData as $v) {
            if (is_string($v) && stripos($v, 'API-TRANSFER') !== false) {
                $merged['bank_remark'] = trim($v);
                break;
            }
        }

        return $merged;
    }

    /**
     * Reference line for intermediary disburse receipts (bank narration when available).
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $eventPayload
     */
    public static function resolveIntermediaryDisplayReference(array $ctx, array $eventPayload = []): string
    {
        foreach ([$eventPayload, $ctx] as $blob) {
            $hit = self::scanPayloadForApiTransfer($blob);
            if ($hit !== null) {
                return $hit;
            }
        }

        foreach ([
            $ctx['bank_remark'] ?? null,
            $ctx['narration'] ?? null,
            $ctx['sendra_payout_reference'] ?? null,
            $ctx['disburse_tracking_id'] ?? null,
            $ctx['centiiv_order_id'] ?? null,
        ] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $formatted = self::formatBankTransferReferenceForDisplay(trim($candidate));
            if ($formatted !== '' && $formatted !== '—') {
                return $formatted;
            }
        }

        return '—';
    }

    private static function unwrapCentiivPayload(array $decoded): array
    {
        $inner = $decoded['data'] ?? null;
        if (is_array($inner)) {
            return $inner;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function flattenCentiivMetadata(array $meta): array
    {
        $inner = $meta['metadata'] ?? null;
        $base = $meta;
        if (is_array($inner)) {
            unset($base['metadata']);

            return array_merge($base, $inner);
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $meta Flattened order metadata (quote.estimatedReceivableAmount, …)
     */
    private static function extractNgnAmount(array $merged, array $meta = []): ?float
    {
        // Terminal webhook / GET /requests payloads include the actual local fiat sent in
        // fulfillments[0].amount. Previously this was only read for NGN, which caused the
        // hero amount to be blank on XOF (Togo/Benin CFA) and other non-NGN mobile-money receipts.
        $toAsset = strtoupper((string) ($merged['toAsset'] ?? $merged['to_asset'] ?? ''));
        $supportedFiats = ['NGN', 'XOF', 'XAF', 'GHS', 'KES', 'UGX', 'TZS', 'ETB', 'RWF', 'MZN', 'ZAR', 'MAD', 'EGP'];
        if (in_array($toAsset, $supportedFiats, true) && isset($merged['fulfillments'][0]) && is_array($merged['fulfillments'][0])) {
            $f0 = $merged['fulfillments'][0];
            $fa = $f0['amount'] ?? $f0['payoutAmount'] ?? $f0['payout_amount'] ?? null;
            if ($fa !== null && $fa !== '' && is_numeric($fa)) {
                return round((float) $fa, 2);
            }
        }

        $quote = $meta['quote'] ?? null;
        if (is_array($quote) && isset($quote['estimatedReceivableAmount']) && is_numeric($quote['estimatedReceivableAmount'])) {
            return round((float) $quote['estimatedReceivableAmount'], 2);
        }

        $summary = SendraCentiivRampService::summarizeQuoteFiat($merged, $toAsset !== '' ? $toAsset : 'NGN', null);
        if ($summary['receive_fiat'] !== null) {
            return $summary['receive_fiat'];
        }

        $cur = strtoupper((string) ($merged['currency'] ?? ''));
        $rawAmt = $merged['amount'] ?? null;
        if ($cur === 'NGN' && is_numeric($rawAmt)) {
            $v = (float) $rawAmt;
            if ($v > 0 && abs($v - round($v)) < 0.00001) {
                return round($v / 100, 2);
            }

            return round($v, 2);
        }

        $nested = $merged['data'] ?? $merged['quote'] ?? $merged;
        if (!is_array($nested)) {
            $nested = $merged;
        }

        foreach ([
            $merged['payoutAmount'] ?? null,
            $merged['payout_amount'] ?? null,
            $nested['payoutAmount'] ?? null,
            $nested['receiveAmount'] ?? null,
            $nested['toAmount'] ?? null,
            $nested['amountNgn'] ?? null,
            $nested['amount_ngn'] ?? null,
        ] as $v) {
            if ($v !== null && $v !== '' && is_numeric($v)) {
                return (float) $v;
            }
        }

        return null;
    }

    private static function formatWhen(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return self::formatReceiptNow('M j, Y g:i A');
        }

        return self::formatIsoInReceiptTz($iso, 'M j, Y g:i A');
    }

    private static function formatIsoInReceiptTz(?string $iso, string $pattern): string
    {
        if ($iso === null || trim($iso) === '') {
            return '—';
        }
        try {
            $dt = new \DateTimeImmutable($iso);

            return $dt->setTimezone(self::receiptDisplayTimezone())->format($pattern);
        } catch (\Throwable $e) {
            return '—';
        }
    }

    /**
     * Reference shown on Sendra receipt — prefer the same id that appears on the bank app (Opay Remark).
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $ful0
     */
    private static function resolveDisplayReference(
        array $merged,
        array $meta,
        array $ful0,
        string $txId,
        ?string $storedPayoutReference = null
    ): string {
        $remark = self::resolveBankRemark($merged, $meta, $ful0);
        if ($remark !== '') {
            $tail = self::extractRemarkTransferReference($remark);
            if ($tail !== null) {
                return $tail;
            }
        }

        $apiTransfer = self::findApiTransferReferenceInPayload($merged, $meta, $ful0);
        if ($apiTransfer !== null) {
            return $apiTransfer;
        }

        $nipSession = self::findNipSessionReferenceInPayload($merged, $meta, $ful0);
        if ($nipSession !== null) {
            return $nipSession;
        }

        foreach ([
            $storedPayoutReference,
            $meta['sendra_payout_reference'] ?? null,
            $meta['trackingId'] ?? null,
            is_string($merged['reference'] ?? null) ? (string) $merged['reference'] : null,
        ] as $ours) {
            if (!is_string($ours)) {
                continue;
            }
            $t = trim($ours);
            if ($t !== '' && self::looksLikeBankTransferReference($t)) {
                return $t;
            }
        }

        $disbursalRef = self::firstNonEmptyString([
            $ful0['disbursalRef'] ?? null,
            $ful0['disbursal_ref'] ?? null,
            $merged['disbursalRef'] ?? null,
            $meta['disbursalRef'] ?? null,
        ]);
        if ($disbursalRef !== null && self::looksLikeBankTransferReference($disbursalRef)) {
            return $disbursalRef;
        }

        // Centiiv often returns an internal UUID for disbursalRef; Opay shows SR-… / API-TRANSFER / NIP session instead.
        if ($disbursalRef !== null && self::isGenericUuid($disbursalRef)) {
            $sr = self::firstNonEmptyString([
                $storedPayoutReference,
                $meta['sendra_payout_reference'] ?? null,
                $meta['trackingId'] ?? null,
            ]);
            if ($sr !== null) {
                return $sr;
            }
        }

        if ($disbursalRef !== null) {
            return $disbursalRef;
        }

        $session = self::firstNonEmptyString([
            $ful0['disbursalSessionId'] ?? null,
            $ful0['disbursal_session_id'] ?? null,
            $meta['sessionId'] ?? null,
        ]);
        if ($session !== null && self::looksLikeBankTransferReference($session) && !self::isGenericUuid($session)) {
            return $session;
        }

        if ($txId !== '' && $txId !== '—' && self::looksLikeBankTransferReference($txId) && !self::isGenericUuid($txId)) {
            return $txId;
        }

        if ($storedPayoutReference !== null && trim($storedPayoutReference) !== '') {
            return trim($storedPayoutReference);
        }

        return '—';
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $ful0
     */
    private static function findApiTransferReferenceInPayload(array $merged, array $meta, array $ful0): ?string
    {
        foreach ([$ful0, $meta, $merged] as $blob) {
            $hit = self::scanPayloadForApiTransfer($blob);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * NIP / Opay "Session ID" style references (often numeric).
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $ful0
     */
    private static function findNipSessionReferenceInPayload(array $merged, array $meta, array $ful0): ?string
    {
        $keys = [
            'nipSessionId',
            'nip_session_id',
            'sessionId',
            'session_id',
            'rrn',
            'api_rrn',
            'coreRef',
            'core_ref',
            'transferReference',
            'transfer_reference',
            'providerReference',
            'provider_reference',
        ];
        foreach ([$ful0, $meta, $merged] as $blob) {
            foreach ($keys as $k) {
                $v = $blob[$k] ?? null;
                if (!is_string($v) && !is_numeric($v)) {
                    continue;
                }
                $t = trim((string) $v);
                if ($t !== '' && preg_match('/^\d{10,30}$/', $t)) {
                    return $t;
                }
            }
            $hit = self::scanPayloadForNipSession($blob);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|mixed $node
     */
    private static function scanPayloadForApiTransfer($node): ?string
    {
        if (!is_array($node)) {
            if (is_string($node) && preg_match('/API-TRANSFER-([A-Za-z0-9-]+)/i', $node, $m)) {
                return $m[1];
            }

            return null;
        }
        foreach ($node as $v) {
            if (is_string($v) && preg_match('/API-TRANSFER-([A-Za-z0-9-]+)/i', $v, $m)) {
                return $m[1];
            }
            if (is_array($v)) {
                $hit = self::scanPayloadForApiTransfer($v);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|mixed $node
     */
    private static function scanPayloadForNipSession($node): ?string
    {
        if (!is_array($node)) {
            return null;
        }
        foreach ($node as $k => $v) {
            if (is_string($k) && preg_match('/session|rrn|ref/i', $k) && (is_string($v) || is_numeric($v))) {
                $t = trim((string) $v);
                if ($t !== '' && preg_match('/^\d{10,30}$/', $t)) {
                    return $t;
                }
            }
            if (is_array($v)) {
                $hit = self::scanPayloadForNipSession($v);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    private static function isGenericUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($value)) === 1;
    }

    /**
     * Full bank narration / remark (e.g. Opay "Remark" field including merchant prefix + API-TRANSFER id).
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $ful0
     */
    private static function resolveBankRemark(array $merged, array $meta, array $ful0): string
    {
        foreach (['remark', 'narration', 'bankRemark', 'bank_remark', 'transferRemark', 'paymentRemark'] as $k) {
            $v = self::firstNonEmptyString([
                $ful0[$k] ?? null,
                $merged[$k] ?? null,
                $meta[$k] ?? null,
            ]);
            if ($v !== null) {
                return $v;
            }
        }

        foreach (['description', 'paymentDescription', 'payment_description'] as $k) {
            $v = self::firstNonEmptyString([
                $ful0[$k] ?? null,
                $merged[$k] ?? null,
                $meta[$k] ?? null,
            ]);
            if ($v !== null && stripos($v, 'API-TRANSFER') !== false) {
                return $v;
            }
        }

        return '';
    }

    /**
     * Bank apps show API-TRANSFER ids without the prefix (e.g. B5D61-5346167d-… not API-TRANSFER-B5D61-…).
     */
    public static function formatBankTransferReferenceForDisplay(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '—') {
            return '—';
        }
        if (preg_match('/API-TRANSFER-([A-Za-z0-9-]+)/i', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    private static function extractRemarkTransferReference(string $remark): ?string
    {
        if (preg_match('/API-TRANSFER-([A-Za-z0-9-]+)/i', $remark, $m)) {
            return $m[1];
        }
        $parts = preg_split('/\|/u', $remark, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($parts) && count($parts) > 1) {
            $tail = trim((string) end($parts));
            if ($tail !== '') {
                return self::formatBankTransferReferenceForDisplay($tail);
            }
        }

        return null;
    }

    private static function looksLikeBankTransferReference(string $value): bool
    {
        if (preg_match('/^API-TRANSFER-/i', $value)) {
            return true;
        }
        if (preg_match('/^[A-Za-z0-9]{3,8}-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return true;
        }
        if (preg_match('/^SR-\d{6}-[A-Fa-f0-9]{8,}$/i', $value)) {
            return true;
        }
        if (preg_match('/^\d{18,}$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * NGN rail sender line on the WhatsApp receipt.
     *
     * Prefer live provider/sender from the rail payload when present; otherwise fall back
     * per provider: UseSendra LTD/Centiiv or UseSendra LTD/Daya.
     *
     * @param array<string, mixed> $merged Centiiv order / Daya deposit (or similar) payload
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $fulfillment
     * @param 'centiiv'|'daya'|null $provider Rail that paid the user; null treats as Centiiv
     */
    public static function resolveReceiptSenderLabel(
        bool $isIntermediaryPayout = false,
        array $merged = [],
        array $meta = [],
        array $fulfillment = [],
        ?string $provider = null
    ): string {
        $fromApi = self::resolveProviderNameFromPayload($merged, $meta, $fulfillment);
        if ($fromApi !== null) {
            return $fromApi;
        }

        $rail = strtolower(trim((string) ($provider ?? '')));
        if ($rail === '') {
            $rail = 'centiiv';
        }

        if ($rail === 'daya') {
            $env = trim((string) ($_ENV['SENDRA_RECEIPT_DAYA_SENDER_LABEL'] ?? getenv('SENDRA_RECEIPT_DAYA_SENDER_LABEL') ?: ''));
            if ($env === '') {
                $env = trim((string) ($_ENV['SENDRA_RECEIPT_OFFRAMP_SENDER_LABEL'] ?? getenv('SENDRA_RECEIPT_OFFRAMP_SENDER_LABEL') ?: ''));
            }

            return $env !== '' ? $env : self::DAYA_OFFRAMP_SENDER_NAME;
        }

        if ($isIntermediaryPayout) {
            $env = trim((string) ($_ENV['SENDRA_RECEIPT_INTERMEDIARY_SENDER_LABEL'] ?? getenv('SENDRA_RECEIPT_INTERMEDIARY_SENDER_LABEL') ?: ''));
            if ($env !== '') {
                return $env;
            }
            $legacy = trim((string) ($_ENV['SENDRA_RECEIPT_LIQUIDITY_LABEL'] ?? getenv('SENDRA_RECEIPT_LIQUIDITY_LABEL') ?: ''));
            if ($legacy !== '') {
                return $legacy;
            }

            return self::INTERMEDIARY_SENDER_NAME;
        }

        $env = trim((string) ($_ENV['SENDRA_RECEIPT_CENTIIV_SENDER_LABEL'] ?? getenv('SENDRA_RECEIPT_CENTIIV_SENDER_LABEL') ?: ''));
        if ($env === '') {
            $env = trim((string) ($_ENV['SENDRA_RECEIPT_OFFRAMP_SENDER_LABEL'] ?? getenv('SENDRA_RECEIPT_OFFRAMP_SENDER_LABEL') ?: ''));
        }

        return $env !== '' ? $env : self::OFFRAMP_SENDER_NAME;
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $meta
     */
    private static function resolvePayoutPartner(array $merged, array $meta, array $fulfillment = []): string
    {
        $env = trim((string) ($_ENV['SENDRA_RECEIPT_LIQUIDITY_LABEL'] ?? getenv('SENDRA_RECEIPT_LIQUIDITY_LABEL') ?: ''));
        if ($env !== '') {
            return $env;
        }

        $fromApi = self::resolveProviderNameFromPayload($merged, $meta, $fulfillment);

        if ($fromApi !== null && $fromApi !== '') {
            return $fromApi;
        }

        // If routing is done via targetNodeId (e.g. Centiiv vs Nomba rail), allow explicit mapping:
        // - Set SENDRA_CENTIIV_TARGET_NODE_LABEL to show a friendly provider name for the configured node id.
        // - Or set SENDRA_CENTIIV_NODE_ID_MAP as JSON: {"<nodeId>":"Nomba","<nodeId2>":"Centiiv"}.
        $targetNodeId = self::firstNonEmptyString([
            $merged['targetNodeId'] ?? null,
            $merged['target_node_id'] ?? null,
            $meta['targetNodeId'] ?? null,
            $meta['target_node_id'] ?? null,
        ]);
        if ($targetNodeId !== null && $targetNodeId !== '') {
            $mapJson = trim((string) ($_ENV['SENDRA_CENTIIV_NODE_ID_MAP'] ?? getenv('SENDRA_CENTIIV_NODE_ID_MAP') ?: ''));
            if ($mapJson !== '') {
                $decoded = json_decode($mapJson, true);
                if (is_array($decoded) && isset($decoded[$targetNodeId]) && is_string($decoded[$targetNodeId])) {
                    $label = trim($decoded[$targetNodeId]);
                    if ($label !== '') {
                        return $label;
                    }
                }
            }

            $envNode = trim((string) ($_ENV['SENDRA_CENTIIV_TARGET_NODE_ID'] ?? getenv('SENDRA_CENTIIV_TARGET_NODE_ID') ?: ''));
            $envLabel = trim((string) ($_ENV['SENDRA_CENTIIV_TARGET_NODE_LABEL'] ?? getenv('SENDRA_CENTIIV_TARGET_NODE_LABEL') ?: ''));
            if ($envNode !== '' && $envLabel !== '' && $envNode === $targetNodeId) {
                return $envLabel;
            }
        }

        return self::INTERMEDIARY_SENDER_NAME;
    }

    /**
     * Centiiv terminal payloads now include the rail/liquidity provider that actually paid the user,
     * commonly as fulfillments[0].lpName (e.g. "Tech9ine Hub").
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $fulfillment
     */
    private static function resolveProviderNameFromPayload(array $merged, array $meta = [], array $fulfillment = []): ?string
    {
        if ($fulfillment === [] && isset($merged['fulfillments'][0]) && is_array($merged['fulfillments'][0])) {
            $fulfillment = $merged['fulfillments'][0];
        }

        $settlement = is_array($merged['settlement_destination'] ?? null)
            ? $merged['settlement_destination']
            : (is_array($merged['settlement'] ?? null) ? $merged['settlement'] : []);

        return self::firstNonEmptyString([
            $fulfillment['lpName'] ?? null,
            $fulfillment['lp_name'] ?? null,
            $fulfillment['liquidityProviderName'] ?? null,
            $fulfillment['liquidity_provider_name'] ?? null,
            $fulfillment['liquidityProvider'] ?? null,
            $fulfillment['liquidity_provider'] ?? null,
            $fulfillment['payoutPartner'] ?? null,
            $fulfillment['payout_partner'] ?? null,
            $fulfillment['providerName'] ?? null,
            $fulfillment['provider_name'] ?? null,
            $fulfillment['senderName'] ?? null,
            $fulfillment['sender_name'] ?? null,
            $fulfillment['senderBankName'] ?? null,
            $fulfillment['sender_bank_name'] ?? null,
            $fulfillment['settlementBankName'] ?? null,
            $fulfillment['settlement_bank_name'] ?? null,
            $fulfillment['sourceBankName'] ?? null,
            $fulfillment['source_bank_name'] ?? null,
            self::stringFromNested($fulfillment['lp'] ?? null, ['name', 'label', 'title']),
            self::stringFromNested($fulfillment['provider'] ?? null, ['name', 'label', 'title']),
            self::stringFromNested($fulfillment['liquidityProvider'] ?? null, ['name', 'label', 'title']),
            // Daya deposit / payout payloads (when present)
            $merged['senderName'] ?? null,
            $merged['sender_name'] ?? null,
            $merged['payout_sender_name'] ?? null,
            $merged['payoutSenderName'] ?? null,
            self::stringFromNested($merged['sender'] ?? null, ['name', 'label', 'title', 'account_name']),
            self::stringFromNested($settlement, ['sender_name', 'senderName', 'provider_name', 'providerName', 'name']),
            self::stringFromNested($settlement['sender'] ?? null, ['name', 'label', 'title', 'account_name']),
            $meta['lpName'] ?? null,
            $meta['lp_name'] ?? null,
            $meta['payout_partner'] ?? null,
            $meta['payoutPartner'] ?? null,
            $meta['liquidity_provider_name'] ?? null,
            $meta['liquidityProviderName'] ?? null,
            $meta['liquidity_provider'] ?? null,
            $meta['liquidityProvider'] ?? null,
            $meta['providerName'] ?? null,
            $meta['provider_name'] ?? null,
            $merged['lpName'] ?? null,
            $merged['lp_name'] ?? null,
            $merged['payoutPartner'] ?? null,
            $merged['payout_partner'] ?? null,
            $merged['liquidityProviderName'] ?? null,
            $merged['liquidity_provider_name'] ?? null,
            $merged['liquidityProvider'] ?? null,
            $merged['liquidity_provider'] ?? null,
            $merged['providerName'] ?? null,
            $merged['provider_name'] ?? null,
            $merged['railName'] ?? null,
            $merged['rail_name'] ?? null,
            self::stringFromNested($merged['targetNode'] ?? null, ['name', 'label', 'title']),
            self::stringFromNested($merged['node'] ?? null, ['name', 'label', 'title']),
        ]);
    }

    /**
     * @param mixed $v
     * @param list<string> $keys
     */
    private static function stringFromNested($v, array $keys): ?string
    {
        if (!is_array($v)) {
            return null;
        }
        foreach ($keys as $k) {
            if (!empty($v[$k]) && is_string($v[$k])) {
                return trim($v[$k]);
            }
        }

        return null;
    }

    /**
     * @param mixed $v
     * @return array<string, mixed>
     */
    private static function coerceArr($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_object($v)) {
            return (array) $v;
        }

        return [];
    }

    /**
     * @param list<mixed> $candidates
     */
    private static function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if ($c === null) {
                continue;
            }
            if (is_string($c)) {
                $t = trim($c);

                return $t !== '' ? $t : null;
            }
            if (is_scalar($c)) {
                $t = trim((string) $c);

                return $t !== '' ? $t : null;
            }
        }

        return null;
    }
}
