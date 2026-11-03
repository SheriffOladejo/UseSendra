<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Custodial Aptos buy/sell via Daya (ONCHAIN in, CRYPTO_ADDRESS + NGN_PAYOUT out).
 */
final class SendraDayaAptosService
{
    public function __construct(private SendraDayaRampService $daya = new SendraDayaRampService())
    {
    }

    public function isConfigured(): bool
    {
        return $this->daya->isConfigured();
    }

    public function chain(): string
    {
        $v = strtoupper(trim((string) ($_ENV['SENDRA_DAYA_APTOS_CHAIN'] ?? getenv('SENDRA_DAYA_APTOS_CHAIN') ?: 'APTOS')));

        return $v !== '' ? $v : 'APTOS';
    }

    /**
     * @return array{ok: bool, error?: string, user_message?: string}
     */
    public function assertAptosSupport(string $asset, string $direction): array
    {
        $asset = strtoupper(trim($asset));
        $chain = $this->chain();
        $support = $this->daya->chainAssetSupport($chain, $asset);
        if (!$support['ok']) {
            return [
                'ok' => false,
                'error' => (string) ($support['error'] ?? 'unsupported'),
                'user_message' => "❌ *{$asset}* on Aptos isn't available on Daya right now.",
            ];
        }
        $need = $direction === 'sell' ? 'deposit_enabled' : 'withdraw_enabled';
        if (empty($support[$need])) {
            return [
                'ok' => false,
                'error' => $need . '_off',
                'user_message' => "❌ Daya cannot " . ($direction === 'sell' ? 'receive' : 'send') . " *{$asset}* on Aptos right now.",
            ];
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function quoteBuy(string $mode, ?float $payNgn, ?float $targetCrypto, string $asset): array
    {
        $asset = strtoupper(trim($asset));
        $gate = $this->assertAptosSupport($asset, 'buy');
        if (!$gate['ok']) {
            return ['success' => false, 'error' => (string) $gate['error'], 'user_message' => (string) $gate['user_message']];
        }
        $rateRes = $this->daya->getRate($asset, 'BUY');
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
            $this->chain()
        );
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     * @return array{success: bool, error?: string, user_message?: string, bank?: array<string, mixed>|null, meta?: array<string, mixed>, instruct_ngn?: string}
     */
    public function createBuyOrder(string $phone, array $userRow, array $ctx): array
    {
        $asset = strtoupper((string) ($ctx['asset'] ?? 'USDC'));
        $aptos = trim((string) ($userRow['aptos_address'] ?? $ctx['user_aptos'] ?? ''));
        if ($aptos === '' || !str_starts_with(strtolower($aptos), '0x')) {
            return ['success' => false, 'error' => 'missing_aptos', 'user_message' => '❌ No Aptos wallet on file. Type *hi* to get started.'];
        }

        $mode = (string) ($ctx['quote_mode'] ?? 'naira');
        $quoted = $mode === 'crypto_target'
            ? $this->quoteBuy('crypto_target', null, (float) ($ctx['target_crypto'] ?? 0), $asset)
            : $this->quoteBuy('naira', (float) ($ctx['pay_ngn'] ?? 0), null, $asset);
        if (empty($quoted['success'])) {
            return $quoted;
        }

        $customer = $this->daya->ensureCustomer($phone, $userRow);
        if (empty($customer['success'])) {
            return [
                'success' => false,
                'error' => (string) ($customer['error'] ?? 'customer'),
                'user_message' => '❌ Could not register this payment. Try again shortly.',
            ];
        }

        $payNgn = (float) $quoted['pay_ngn'];
        $feePct = SendraDayaRampService::developerFeePct();
        $body = [
            'type' => 'TEMPORARY',
            'rail' => 'NGN_VIRTUAL_ACCOUNT',
            'customer' => ['customer_id' => $customer['customer_id']],
            'currency' => 'NGN',
            'amount' => SendraDayaRampService::wholeNairaPrincipal($payNgn),
            'settlement_destination' => [
                'type' => 'ONCHAIN',
                'rate_id' => $quoted['rate_id'],
                'destination_asset' => $asset,
                'destination_chain' => $this->chain(),
                'destination_address' => $aptos,
            ],
        ];
        if ((float) $feePct > 0) {
            $body['developer_fee'] = ['percentage' => $feePct];
        }

        $created = $this->daya->createFundingAccount($body, 'sendra-aptos-buy-' . bin2hex(random_bytes(10)));
        if (empty($created['success'])) {
            return [
                'success' => false,
                'error' => (string) ($created['error'] ?? 'funding_account'),
                'user_message' => '❌ Could not open a Naira account for this payment. Try again or email support@usesendra.com',
            ];
        }

        $account = $created['account'];
        $instruct = (string) ($account['amount'] ?? number_format((float) ($quoted['instruct_ngn'] ?? $payNgn), 2, '.', ''));
        $bank = SendraDayaRampService::extractActiveBankInstruction($account);
        $meta = [
            'kind' => 'daya_aptos_onramp',
            'daya_funding_account_id' => (string) ($account['id'] ?? ''),
            'daya_customer_id' => (string) $customer['customer_id'],
            'daya_rate_id' => (string) $quoted['rate_id'],
            'daya_rate' => (float) $quoted['rate'],
            'daya_fee_bps' => (int) ($quoted['fee_bps'] ?? 0),
            'daya_expires_at' => (string) ($quoted['expires_at'] ?? ''),
            'destination_address' => $aptos,
            'destination_chain' => $this->chain(),
            'destination_asset' => $asset,
            'dest_chain' => 'aptos',
            'sendra_dest_chain' => 'aptos',
            'user_aptos' => $aptos,
            'pay_ngn' => $payNgn,
            'pay_ngn_display' => number_format($payNgn, 2, '.', ','),
            'instruct_ngn' => $instruct,
            'receive_crypto' => (float) $quoted['receive_crypto'],
            'receive_crypto_display' => (string) $quoted['receive_crypto_display'],
            'centiiv_crypto_received' => (float) $quoted['receive_crypto'],
            'centiiv_crypto_asset' => $asset,
            'ledger_asset' => $asset,
            'quote_mode' => $mode,
            'virtual_bank' => $bank,
            'developer_fee_pct' => $feePct,
            'rate_hint' => (string) ($quoted['rate_hint'] ?? ''),
            'fee_hint' => (string) ($quoted['fee_hint'] ?? ''),
            'need_oneclick_poll' => false,
        ];

        return [
            'success' => true,
            'bank' => $bank,
            'instruct_ngn' => $instruct,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quoteSell(float $cryptoAmount, string $asset): array
    {
        $asset = strtoupper(trim($asset));
        $gate = $this->assertAptosSupport($asset, 'sell');
        if (!$gate['ok']) {
            return ['success' => false, 'error' => (string) $gate['error'], 'user_message' => (string) $gate['user_message']];
        }
        $rateRes = $this->daya->getRate($asset, 'SELL');
        if (!$rateRes['success']) {
            return [
                'success' => false,
                'error' => (string) ($rateRes['error'] ?? 'rate_unavailable'),
                'user_message' => "❌ No sell rate available right now.\n\nTry again shortly.",
            ];
        }
        $fees = $this->daya->getFees();
        $feeRow = !empty($fees['success']) && is_array($fees['fees'] ?? null) ? $fees['fees'] : [];

        return SendraDayaFeeMath::quoteSellCrypto($cryptoAmount, $rateRes['rate'], $feeRow, $asset)
            + ['rate_row' => $rateRes['rate'], 'fees' => $feeRow];
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $meta
     * @return array{success: bool, error?: string, user_message?: string, deposit_address?: string, funding_account_id?: string, meta_patch?: array<string, mixed>}
     */
    public function createSellFundingAccount(string $phone, array $userRow, array $meta): array
    {
        $asset = strtoupper(trim((string) ($meta['asset'] ?? $meta['ledger_asset'] ?? 'USDC')));
        $crypto = (float) ($meta['crypto_amount'] ?? 0);
        $gate = $this->assertAptosSupport($asset, 'sell');
        if (!$gate['ok']) {
            return ['success' => false, 'error' => (string) $gate['error'], 'user_message' => (string) $gate['user_message']];
        }

        $quoted = $this->quoteSell($crypto, $asset);
        if (empty($quoted['success'])) {
            return $quoted;
        }

        $quotedRecv = (float) ($meta['sendra_quoted_receive_ngn'] ?? 0);
        $freshRecv = (float) ($quoted['receive_ngn'] ?? 0);
        if ($quotedRecv > 0 && $freshRecv + 1e-9 < $quotedRecv * 0.98) {
            return [
                'success' => false,
                'error' => 'rate_moved',
                'user_message' => "❌ The rate moved. You would receive about *₦" . number_format($freshRecv, 0, '.', ',') . "* now.\n\nType *sell* to get a fresh quote.",
            ];
        }

        $flag = $meta['sendra_intermediary_payout'] ?? null;
        $requested = (float) ($meta['target_receive_ngn'] ?? $meta['sendra_requested_ngn'] ?? 0);
        $usePool = SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()
            && $requested > 0
            && $flag !== '0'
            && $flag !== 0
            && $flag !== false;
        $destBank = $usePool ? $this->poolDestinationBank() : $this->userDestinationBank($meta);
        if ($destBank === null) {
            return [
                'success' => false,
                'error' => 'bank_map',
                'user_message' => '❌ Could not map that bank for payout. Try again or email support@usesendra.com',
            ];
        }
        if ($usePool) {
            $requested = (float) ($meta['target_receive_ngn'] ?? $meta['sendra_requested_ngn'] ?? 0);
            $floor = SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()
                ? SendraCentiivRampService::intermediaryPoolFloorNgn($requested)
                : $requested;
            if ($requested > 0 && $freshRecv + 1e-9 < $floor) {
                return [
                    'success' => false,
                    'error' => 'pool_short',
                    'user_message' => "❌ The rate moved — we can't guarantee *₦" . number_format($requested, 0, '.', ',') . "* right now. Type *sell* to retry.",
                ];
            }
        }

        $customer = $this->daya->ensureCustomer($phone, $userRow);
        if (empty($customer['success'])) {
            return [
                'success' => false,
                'error' => 'customer',
                'user_message' => '❌ Could not register this payout. Try again shortly.',
            ];
        }

        $feePct = SendraDayaRampService::developerFeePct();
        $body = [
            'type' => 'TEMPORARY',
            'rail' => 'CRYPTO_ADDRESS',
            'customer' => ['customer_id' => $customer['customer_id']],
            'asset' => $asset,
            'chain' => $this->chain(),
            'settlement_destination' => [
                'type' => 'NGN_PAYOUT',
                'rate_id' => $quoted['rate_id'],
                'destination_bank' => [
                    'account_number' => $destBank['account_number'],
                    'bank_code' => $destBank['bank_code'],
                ],
            ],
        ];
        if ((float) $feePct > 0) {
            $body['developer_fee'] = ['percentage' => $feePct];
        }

        $created = $this->daya->createFundingAccount($body, 'sendra-aptos-sell-' . bin2hex(random_bytes(10)));
        if (empty($created['success'])) {
            return [
                'success' => false,
                'error' => (string) ($created['error'] ?? 'funding_account'),
                'user_message' => '❌ Could not open a payout address. Try again shortly.',
            ];
        }
        $account = $created['account'];
        $addr = SendraDayaRampService::extractCryptoAddress($account);
        if ($addr === null || $addr === '') {
            return [
                'success' => false,
                'error' => 'no_deposit_address',
                'user_message' => '❌ Daya did not return a deposit address. Try again shortly.',
            ];
        }

        $patch = [
            'daya_funding_account_id' => (string) ($account['id'] ?? ''),
            'daya_customer_id' => (string) $customer['customer_id'],
            'daya_rate_id' => (string) $quoted['rate_id'],
            'daya_rate' => (float) $quoted['rate'],
            'daya_sell_receive_ngn' => $freshRecv,
            'sendra_quoted_receive_ngn' => $freshRecv,
            'developer_fee_pct' => $feePct,
            'sendra_intermediary_payout' => $usePool ? '1' : '0',
        ];
        if ($usePool) {
            $patch['sendra_requested_ngn'] = (string) round((float) ($meta['target_receive_ngn'] ?? 0), 2);
        }

        return [
            'success' => true,
            'deposit_address' => $addr,
            'funding_account_id' => (string) ($account['id'] ?? ''),
            'meta_patch' => $patch,
        ];
    }

    /**
     * @return array{account_number: string, bank_code: string}|null
     */
    public function poolDestinationBank(): ?array
    {
        $pool = SendraCentiivRampService::sendraIntermediaryNgnAccount();
        if ($pool === null) {
            return null;
        }
        $code = $this->dayaBankCodeForPool($pool);
        if ($code === null || $code === '') {
            return null;
        }

        return [
            'account_number' => $pool['account_number'],
            'bank_code' => $code,
        ];
    }

    /**
     * @param array<string, mixed> $pool
     */
    private function dayaBankCodeForPool(array $pool): ?string
    {
        $override = trim((string) ($_ENV['SENDRA_DAYA_POOL_BANK_CODE'] ?? getenv('SENDRA_DAYA_POOL_BANK_CODE') ?: ''));
        if ($override !== '') {
            return $override;
        }
        $listed = $this->daya->listBanks();
        $banks = $listed['banks'] ?? [];
        $guess = trim((string) ($pool['bank_name'] ?? 'nombank'));
        $hit = SendraDayaRampService::resolveBankCodeByName($guess, $banks);
        if ($hit !== null) {
            return $hit;
        }
        foreach (['nombank', 'nomba', 'amucha'] as $alias) {
            $hit = SendraDayaRampService::resolveBankCodeByName($alias, $banks);
            if ($hit !== null) {
                return $hit;
            }
        }
        $centiiv = preg_replace('/\D+/', '', (string) ($pool['bank_code'] ?? '')) ?? '';
        if ($centiiv !== '') {
            return SendraDayaRampService::mapExternalBankCodeToDaya($centiiv, $banks);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{account_number: string, bank_code: string}|null
     */
    private function userDestinationBank(array $meta): ?array
    {
        $acct = trim((string) ($meta['account_number'] ?? $meta['sendra_user_account_number'] ?? ''));
        $dayaCode = trim((string) ($meta['daya_bank_code'] ?? ''));
        if ($acct === '' || strlen($acct) !== 10) {
            return null;
        }
        if ($dayaCode !== '') {
            return ['account_number' => $acct, 'bank_code' => $dayaCode];
        }
        $name = trim((string) ($meta['bank_name'] ?? $meta['sendra_user_bank_name'] ?? ''));
        $listed = $this->daya->listBanks();
        $banks = $listed['banks'] ?? [];
        $code = SendraDayaRampService::resolveBankCodeByName($name, $banks);
        if ($code === null) {
            $centiiv = (string) ($meta['bank_code'] ?? $meta['sendra_user_bank_code'] ?? '');
            $code = SendraDayaRampService::mapExternalBankCodeToDaya($centiiv, $banks);
        }
        if ($code === null || $code === '') {
            return null;
        }

        return ['account_number' => $acct, 'bank_code' => $code];
    }

    /**
     * @param list<array{code: string, name: string}> $banks
     */
    public function mapNameToDayaCode(string $bankName): ?string
    {
        $listed = $this->daya->listBanks();
        if (empty($listed['success'])) {
            return null;
        }

        return SendraDayaRampService::resolveBankCodeByName($bankName, $listed['banks'] ?? []);
    }
}
