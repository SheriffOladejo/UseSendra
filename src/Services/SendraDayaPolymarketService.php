<?php

declare(strict_types=1);

namespace App\Services;

/**
 * NGN → Daya → USDT on Polygon for a user-supplied Polymarket deposit address.
 */
final class SendraDayaPolymarketService
{
    public function __construct(private ?SendraDayaRampService $daya = null)
    {
        $this->daya = $daya ?? new SendraDayaRampService();
    }

    public function isConfigured(): bool
    {
        return $this->daya->isConfigured();
    }

    public function settlementChain(): string
    {
        $v = strtoupper(trim((string) ($_ENV['SENDRA_DAYA_CHAIN'] ?? getenv('SENDRA_DAYA_CHAIN') ?: 'POLYGON')));

        return $v !== '' ? $v : 'POLYGON';
    }

    public function settlementAsset(): string
    {
        $v = strtoupper(trim((string) ($_ENV['SENDRA_DAYA_ASSET'] ?? getenv('SENDRA_DAYA_ASSET') ?: 'USDT')));

        return $v !== '' ? $v : 'USDT';
    }

    public function developerFeePct(): string
    {
        $raw = trim((string) ($_ENV['SENDRA_DAYA_DEVELOPER_FEE_PCT'] ?? getenv('SENDRA_DAYA_DEVELOPER_FEE_PCT') ?: '0.5'));
        if ($raw === '' || !is_numeric($raw)) {
            return '0.5';
        }
        $n = (float) $raw;
        if ($n < 0) {
            $n = 0;
        }
        if ($n > 50) {
            $n = 50;
        }

        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @return array{ok: bool, error?: string, user_message?: string}
     */
    public function assertOnchainPayout(): array
    {
        $chain = $this->settlementChain();
        $asset = $this->settlementAsset();
        $support = $this->daya->chainAssetSupport($chain, $asset);
        if (!$support['ok']) {
            $err = (string) ($support['error'] ?? 'unsupported');
            $msg = $err === 'chain_not_listed'
                ? "❌ *{$chain}* is not available on Daya in this environment."
                : "❌ *{$asset}* on *{$chain}* is not listed by Daya right now.";
            if ($this->daya->isSandbox() && $chain === 'POLYGON') {
                $msg .= "\n\nSandbox only supports Ethereum. Polymarket (Polygon USDT) needs a *production* Daya key.";
            }

            return ['ok' => false, 'error' => $err, 'user_message' => $msg];
        }
        if (empty($support['withdraw_enabled'])) {
            $msg = "❌ Daya cannot send *{$asset}* to wallets on *{$chain}* yet (`withdraw_enabled` is off).";
            if ($asset === 'USDC' && $chain === 'POLYGON') {
                $msg .= "\n\nPolygon USDT payout is live; USDC payout is not.";
            }

            return ['ok' => false, 'error' => 'withdraw_disabled', 'user_message' => $msg];
        }

        return ['ok' => true];
    }

    /**
     * @return array{success: bool, error?: string, user_message?: string, pay_ngn?: float, receive_crypto?: float, receive_crypto_display?: string, rate_id?: string, rate?: float, fee_bps?: int, fee_hint?: string, rate_hint?: string, expires_at?: string, min_deposit_ngn?: float, quote_mode?: string}
     */
    public function quoteNaira(float $payNgn): array
    {
        return $this->quote('naira', $payNgn, null);
    }

    /**
     * @return array{success: bool, error?: string, user_message?: string, pay_ngn?: float, receive_crypto?: float, receive_crypto_display?: string, rate_id?: string, rate?: float, fee_bps?: int, fee_hint?: string, rate_hint?: string, expires_at?: string, min_deposit_ngn?: float, quote_mode?: string}
     */
    public function quoteCrypto(float $targetUsdt): array
    {
        return $this->quote('crypto_target', null, $targetUsdt);
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     * @return array{success: bool, error?: string, user_message?: string, funding_account_id?: string, bank?: array<string, mixed>|null, instruct_ngn?: string, meta?: array<string, mixed>}
     */
    public function createOnramp(string $phone, array $userRow, array $ctx): array
    {
        $gate = $this->assertOnchainPayout();
        if (!$gate['ok']) {
            return ['success' => false, 'error' => (string) ($gate['error'] ?? 'chain'), 'user_message' => (string) $gate['user_message']];
        }

        $address = trim((string) ($ctx['polygon_address'] ?? ''));
        if ($address === '') {
            return ['success' => false, 'error' => 'missing_address', 'user_message' => '❌ Missing Polymarket address. Type *polymarket* to start again.'];
        }

        $asset = $this->settlementAsset();
        $chain = $this->settlementChain();
        $quoted = $this->quoteFromContext($ctx);
        if (!$quoted['success']) {
            return $quoted;
        }

        $customer = $this->daya->ensureCustomer($phone, $userRow);
        if (!$customer['success']) {
            return [
                'success' => false,
                'error' => (string) ($customer['error'] ?? 'customer'),
                'user_message' => '❌ Could not register this payment with Daya. Try again shortly.',
            ];
        }

        $payNgn = (float) $quoted['pay_ngn'];
        $feePct = $this->developerFeePct();
        $body = [
            'type' => 'TEMPORARY',
            'rail' => 'NGN_VIRTUAL_ACCOUNT',
            'customer' => [
                'customer_id' => $customer['customer_id'],
            ],
            'currency' => 'NGN',
            'amount' => SendraDayaRampService::wholeNairaPrincipal($payNgn),
            'settlement_destination' => [
                'type' => 'ONCHAIN',
                'rate_id' => $quoted['rate_id'],
                'destination_asset' => $asset,
                'destination_chain' => $chain,
                'destination_address' => $address,
            ],
        ];
        if ((float) $feePct > 0) {
            $body['developer_fee'] = ['percentage' => $feePct];
        }

        $idem = 'sendra-pm-' . bin2hex(random_bytes(12));
        $created = $this->daya->createFundingAccount($body, $idem);
        if (!$created['success']) {
            return [
                'success' => false,
                'error' => (string) ($created['error'] ?? 'funding_account'),
                'user_message' => '❌ Could not open a Naira account for this payment. Try again or email support@usesendra.com',
            ];
        }

        $account = $created['account'];
        $instruct = (string) ($account['amount'] ?? number_format($payNgn, 2, '.', ''));
        $bank = SendraDayaRampService::extractActiveBankInstruction($account);
        $meta = [
            'kind' => 'daya_polymarket',
            'daya_funding_account_id' => (string) ($account['id'] ?? ''),
            'daya_customer_id' => (string) $customer['customer_id'],
            'daya_rate_id' => (string) $quoted['rate_id'],
            'destination_address' => $address,
            'destination_chain' => $chain,
            'destination_asset' => $asset,
            'pay_ngn' => $payNgn,
            'instruct_ngn' => $instruct,
            'receive_crypto_display' => (string) $quoted['receive_crypto_display'],
            'quote_mode' => (string) ($ctx['quote_mode'] ?? $quoted['quote_mode'] ?? 'naira'),
            'virtual_bank' => $bank,
            'developer_fee_pct' => $feePct,
        ];

        return [
            'success' => true,
            'funding_account_id' => (string) ($account['id'] ?? ''),
            'bank' => $bank,
            'instruct_ngn' => $instruct,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array{success: bool, error?: string, user_message?: string, pay_ngn?: float, receive_crypto?: float, receive_crypto_display?: string, rate_id?: string, rate?: float, fee_bps?: int, fee_hint?: string, rate_hint?: string, expires_at?: string, min_deposit_ngn?: float, quote_mode?: string}
     */
    public function quoteFromContext(array $ctx): array
    {
        $mode = (string) ($ctx['quote_mode'] ?? 'naira');
        if ($mode === 'crypto_target') {
            return $this->quoteCrypto((float) ($ctx['target_crypto'] ?? 0));
        }

        return $this->quoteNaira((float) ($ctx['pay_ngn'] ?? 0));
    }

    /**
     * @return array{success: bool, error?: string, user_message?: string, pay_ngn?: float, receive_crypto?: float, receive_crypto_display?: string, rate_id?: string, rate?: float, fee_bps?: int, fee_hint?: string, rate_hint?: string, expires_at?: string, min_deposit_ngn?: float, quote_mode?: string}
     */
    private function quote(string $mode, ?float $payNgn, ?float $targetCrypto): array
    {
        $gate = $this->assertOnchainPayout();
        if (!$gate['ok']) {
            return ['success' => false, 'error' => (string) ($gate['error'] ?? 'chain'), 'user_message' => (string) $gate['user_message']];
        }

        $asset = $this->settlementAsset();
        $rateRes = $this->daya->getBuyRate($asset);
        if (!$rateRes['success']) {
            return [
                'success' => false,
                'error' => (string) ($rateRes['error'] ?? 'rate_unavailable'),
                'user_message' => '❌ No buy rate available right now. Try again shortly.',
            ];
        }
        $fees = $this->daya->getFees();
        $feeRow = !empty($fees['success']) && is_array($fees['fees'] ?? null) ? $fees['fees'] : [];

        return SendraDayaFeeMath::quoteBuy(
            $mode,
            $payNgn,
            $targetCrypto,
            $rateRes['rate'],
            $feeRow,
            $asset,
            $this->settlementChain()
        );
    }
}
