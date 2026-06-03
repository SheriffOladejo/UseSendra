<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Builds a display model for Sendra × Centiiv bank payout receipts (webhook + optional GET /requests/{id}).
 */
final class SendraCentiivPayoutReceiptFactory
{
    /** Default sender line on payout receipts when API/env do not specify a provider. */
    private const DEFAULT_SENDER_NAME = 'Nomba Financial Services';
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
                $merged = array_merge($eventData, $apiSlice);
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

        $amount = self::extractNgnAmount($merged, $meta);
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

        $payoutPartner = self::resolvePayoutPartner($merged, $meta);
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

        // Crypto amount withdrawn + fees (often found under metadata.metadata.quote + sendra_ledger_* fields)
        $ledgerAsset = self::firstNonEmptyString([
            is_string($meta['sendra_ledger_asset'] ?? null) ? (string) $meta['sendra_ledger_asset'] : null,
            is_string($merged['fromAsset'] ?? null) ? (string) $merged['fromAsset'] : null,
            is_string($merged['from_asset'] ?? null) ? (string) $merged['from_asset'] : null,
        ]) ?? 'USDT';
        $ledgerAmtRaw = self::firstNonEmptyString([
            is_string($meta['sendra_ledger_amount'] ?? null) ? (string) $meta['sendra_ledger_amount'] : null,
            is_numeric($merged['amount'] ?? null) ? (string) $merged['amount'] : null,
        ]);
        $ledgerAmt = is_numeric($ledgerAmtRaw ?? null) ? (float) $ledgerAmtRaw : null;

        $quote = is_array($meta['quote'] ?? null) ? $meta['quote'] : null;
        $conversionRate = self::resolveConversionRateDisplay(
            $amount,
            $ledgerAmt,
            strtoupper(trim((string) $ledgerAsset)),
            $merged,
            $quote
        );

        $displayReference = self::resolveDisplayReference($merged, $meta, $ful0, $txId, $storedPayoutReference);

        return [
            // Legacy fields (used by older receipt design)
            'recipient' => $recipient,
            'bank_line' => $bankLine,

            // New receipt design fields
            'bank_name' => $bankName !== '' ? $bankName : '—',
            'account_name' => $recipient,
            'account_number' => $acct,
            'sender_name' => self::resolveReceiptSenderLabel(),
            'withdraw_asset' => strtoupper(trim((string) $ledgerAsset)),
            'withdraw_amount' => $ledgerAmt,
            'conversion_rate' => $conversionRate,

            'amount_ngn' => $amount,
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
    private static function resolveConversionRateDisplay(
        ?float $amountNgn,
        ?float $withdrawAmount,
        string $asset,
        array $merged,
        ?array $quote
    ): string {
        if ($asset === '') {
            $asset = 'USDC';
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

        return '₦' . number_format($rate, 2, '.', ',');
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
        // Terminal webhook payloads often include the actual NGN sent in fulfillments[0].amount.
        // Prefer that when the destination rail is NGN.
        $toAsset = strtoupper((string) ($merged['toAsset'] ?? $merged['to_asset'] ?? ''));
        if ($toAsset === 'NGN' && isset($merged['fulfillments'][0]) && is_array($merged['fulfillments'][0])) {
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

        $summary = SendraCentiivRampService::summarizeQuoteNgn($merged, null);
        if ($summary['receive_ngn'] !== null) {
            return $summary['receive_ngn'];
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
            if (is_string($node) && preg_match('/(API-TRANSFER-[A-Za-z0-9-]+)/i', $node, $m)) {
                return $m[1];
            }

            return null;
        }
        foreach ($node as $v) {
            if (is_string($v) && preg_match('/(API-TRANSFER-[A-Za-z0-9-]+)/i', $v, $m)) {
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

        return '';
    }

    private static function extractRemarkTransferReference(string $remark): ?string
    {
        if (preg_match('/(API-TRANSFER-[A-Za-z0-9-]+)/i', $remark, $m)) {
            return $m[1];
        }
        $parts = preg_split('/\|/u', $remark, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($parts) && count($parts) > 1) {
            $tail = trim((string) end($parts));
            if ($tail !== '') {
                return $tail;
            }
        }

        return null;
    }

    private static function looksLikeBankTransferReference(string $value): bool
    {
        if (preg_match('/^API-TRANSFER-/i', $value)) {
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

    /** NGN rail sender line on the WhatsApp receipt (Opay shows Nomba, not the crypto LP). */
    private static function resolveReceiptSenderLabel(): string
    {
        $env = trim((string) ($_ENV['SENDRA_RECEIPT_LIQUIDITY_LABEL'] ?? getenv('SENDRA_RECEIPT_LIQUIDITY_LABEL') ?: ''));

        return $env !== '' ? $env : self::DEFAULT_SENDER_NAME;
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $meta
     */
    private static function resolvePayoutPartner(array $merged, array $meta): string
    {
        $env = trim((string) ($_ENV['SENDRA_RECEIPT_LIQUIDITY_LABEL'] ?? getenv('SENDRA_RECEIPT_LIQUIDITY_LABEL') ?: ''));
        if ($env !== '') {
            return $env;
        }

        $fromApi = self::firstNonEmptyString([
            $meta['payout_partner'] ?? null,
            $meta['liquidity_provider'] ?? null,
            $meta['liquidityProvider'] ?? null,
            $merged['liquidityProvider'] ?? null,
            $merged['liquidity_provider'] ?? null,
            $merged['provider'] ?? null,
            $merged['providerName'] ?? null,
            $merged['provider_name'] ?? null,
            $merged['railName'] ?? null,
            $merged['rail_name'] ?? null,
            self::stringFromNested($merged['targetNode'] ?? null, ['name', 'label', 'title']),
            self::stringFromNested($merged['node'] ?? null, ['name', 'label', 'title']),
        ]);

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

        return self::DEFAULT_SENDER_NAME;
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
