<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use App\Models\User;
use App\Services\SendraCentiivRampService;
use App\Services\SendraOfframpBridge;
use App\Services\SendraOneClickService;
use App\Services\WhatsAppService;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Persistence\PendingTransactionRepository;
use AptosV2\Steps\Kyc\Gate as KycGate;
use AptosV2\Support\V2PinConfirmUrls;

/**
 * Bank verify → quote → 1Click dry quote → PIN queue (USDC Phase 1).
 */
final class ExecutionService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private User $userModel,
        private PendingTransactionRepository $pendingTx,
        private BankService $bankService,
        private QuoteService $quoteService,
    ) {
    }

    public function finalizeBankAndQueuePin(
        StepContext $ctx,
        string $accountNumber,
        string $bankNameGuess
    ): StepResult {
        $from = $ctx->from;
        $userRow = $ctx->user;
        $sellCtx = $this->stateToSellCtx($ctx);
        $chain = strtolower((string) ($sellCtx['wallet_chain'] ?? 'aptos'));

        if ($chain !== 'stellar' && !\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            if ($missing = Guard::completeIfOneclickMissing($ctx, $this->whatsapp)) {
                return $missing;
            }
        }

        $agg = new SendraCentiivRampService();

        if (!$this->bankService->verifyAndPopulate($from, $sellCtx, $accountNumber, $bankNameGuess, $agg)) {
            return StepResult::stay(['action' => 'sell_bank_verify_failed']);
        }

        if (empty($sellCtx['send_naira_quick']) && empty($sellCtx['receive_ngn_display'])) {
            $this->whatsapp->sendMessage($from, '⏳ Getting a rate…');
            if (!$this->quoteService->applyDirectQuote($from, $userRow, $sellCtx, $agg)) {
                return StepResult::stay(['action' => 'sell_quote_failed']);
            }
        }

        $receiveNgn = self::receiveNgnFromSellCtx($sellCtx);
        if ($receiveNgn > 0) {
            $resume = array_merge($this->stateToSellCtx($ctx), $sellCtx);
            $resume['flow'] = 'sell';
            $resume['step'] = 'bank';
            if ($kycBlock = KycGate::blockIfNeeded($ctx, $receiveNgn, $resume)) {
                return $kycBlock;
            }
        }

        if ($chain === 'arc') {
            return $this->queueArcPin($from, $userRow, $sellCtx);
        }

        if ($chain === 'stellar') {
            return $this->queueStellarPin($from, $userRow, $sellCtx);
        }

        if (\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            $this->attachDayaBankCode($sellCtx);

            return $this->queueDayaPin($from, $userRow, $sellCtx);
        }

        return $this->queueOneClickPin($from, $userRow, $sellCtx);
    }

    /**
     * Send-naira: sized ctx from {@see NairaSizingService} → bank verify → PIN (1Click + Centiiv).
     *
     * @param array<string, mixed> $sellCtx
     */
    public function finalizeSendNairaAfterSizing(StepContext $ctx, array $sellCtx): StepResult
    {
        if (!\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            if ($missing = Guard::completeIfOneclickMissing($ctx, $this->whatsapp)) {
                return $missing;
            }
        }

        $from = $ctx->from;
        $account = trim((string) ($sellCtx['preset_account'] ?? ''));
        $bankGuess = trim((string) ($sellCtx['preset_bank_guess'] ?? ''));
        if ($account === '' || $bankGuess === '') {
            $this->whatsapp->sendMessage($from, '❌ Bank details missing. Type *cancel* and try again.');

            return StepResult::stay(['action' => 'sell_send_naira_bank_missing']);
        }

        $agg = new SendraCentiivRampService();
        if (!$this->bankService->verifyAndPopulate($from, $sellCtx, $account, $bankGuess, $agg)) {
            return StepResult::stay(['action' => 'sell_send_naira_bank_failed']);
        }

        $receiveNgn = self::receiveNgnFromSellCtx($sellCtx);
        if ($receiveNgn > 0) {
            $resume = $sellCtx;
            $resume['flow'] = 'sell';
            $resume['step'] = 'asset';
            if ($kycBlock = KycGate::blockIfNeeded($ctx, $receiveNgn, $resume)) {
                return $kycBlock;
            }
        }

        $chain = strtolower((string) ($sellCtx['wallet_chain'] ?? 'aptos'));
        if ($chain === 'arc') {
            return $this->queueArcPin($from, $ctx->user, $sellCtx);
        }

        if ($chain === 'stellar') {
            return $this->queueStellarPin($from, $ctx->user, $sellCtx);
        }

        if (\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            $this->attachDayaBankCode($sellCtx);

            return $this->queueDayaPin($from, $ctx->user, $sellCtx);
        }

        return $this->queueOneClickPin($from, $ctx->user, $sellCtx);
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     */
    private function queueOneClickPin(string $from, array $userRow, array $ctx): StepResult
    {
        $asset = (string) ($ctx['asset'] ?? '');
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $acct = (string) ($ctx['account_number'] ?? '');
        $name = (string) ($ctx['account_name'] ?? '');
        $bankDisplay = (string) ($ctx['bank_name'] ?? '');

        $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        $originUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near'));

        $refundAptos = trim((string) ($userRow['aptos_address'] ?? ''));
        if ($refundAptos === '') {
            $this->whatsapp->sendMessage($from, '❌ Missing Aptos address on your account. Contact support.');

            return StepResult::complete(['action' => 'sell_no_wallet']);
        }

        $originAsset = $asset === 'USDC' ? $originUsdc : $originUsdt;
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $amountSmallest = SendraOneClickService::cryptoAmountToSmallestUnits($amountStr, 6);
        $oneClick = new SendraOneClickService();

        $prefetched = null;
        if (isset($ctx['oneclick_live_quote_data']) && is_array($ctx['oneclick_live_quote_data'])) {
            $prefetched = $ctx['oneclick_live_quote_data'];
        } elseif (isset($ctx['oneclick_dry_quote_data']) && is_array($ctx['oneclick_dry_quote_data'])) {
            $prefetched = $ctx['oneclick_dry_quote_data'];
        }

        if ($prefetched !== null) {
            $quoteDryRaw = $prefetched;
            $parsedDry = SendraOneClickService::parseQuoteResponse($quoteDryRaw);
        } else {
            $quoteBodyDry = SendraOneClickService::buildAptosToOfframpUsdcQuoteBody(
                $originAsset,
                $amountSmallest,
                SendraOfframpBridge::dryRecipient(),
                $refundAptos,
                $deadlineIso,
                true
            );
            $quoteDryRes = $oneClick->requestQuote($quoteBodyDry);
            if (!$quoteDryRes['success']) {
                $err = $quoteDryRes['error'] ?? '1Click dry quote failed';
                error_log("Sendra sell v2: 1Click dry quote failed {$from}: {$err} " . json_encode($quoteDryRes['data'] ?? null));
                $this->whatsapp->sendMessage(
                    $from,
                    "❌ Could not get a quote right now.\n\nTry again in a minute or contact support@usesendra.com"
                );

                return StepResult::stay(['action' => 'sell_oneclick_quote_failed']);
            }
            $quoteDryRaw = $quoteDryRes['data'];
            $parsedDry = SendraOneClickService::parseQuoteResponse($quoteDryRaw);
        }
        if ($parsedDry['correlation_id'] === null) {
            error_log('Sendra sell v2: 1Click dry quote missing correlation: ' . json_encode($quoteDryRaw));
            $this->whatsapp->sendMessage($from, '❌ Quote response incomplete. Please try again.');

            return StepResult::stay(['action' => 'sell_oneclick_quote_incomplete']);
        }

        $usdcDrySmallest = $parsedDry['amount_out'] ?? '0';
        $centiivUsdcAmount = SendraOfframpBridge::quotedUsdcOut($parsedDry);
        if ($centiivUsdcAmount <= 0) {
            $this->whatsapp->sendMessage($from, '❌ Invalid quoted USDC amount. Please try a different size.');

            return StepResult::stay(['action' => 'sell_oneclick_usdc_invalid']);
        }

        $pendingId = $this->pendingTx->create([
            'user_phone' => $from,
            'tx_type' => 'offramp_oneclick_centiiv',
            'amount' => $amountStr,
            'currency' => $asset,
            'recipient_address' => $refundAptos,
            'human_description' => json_encode([
                'kind' => 'oneclick_centiiv_offramp',
                'aptos_v2' => true,
                'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
                'centiiv_order_deferred' => true,
                'oneclick_correlation_id_dry' => $parsedDry['correlation_id'],
                'oneclick_dry_quote_data' => $quoteDryRaw,
                'centiiv_usdc_amount' => $centiivUsdcAmount,
                'usdc_dry_smallest' => $usdcDrySmallest,
                'fiat' => $ctx['fiat'] ?? 'NGN',
                'target_receive_ngn' => (float) ($ctx['target_receive_ngn'] ?? 0),
                'receive_ngn_display' => $ctx['receive_ngn_display'] ?? null,
                'sendra_quoted_receive_ngn' => (float) ($ctx['sendra_quoted_receive_ngn'] ?? 0),
                'account_name' => $name,
                'account_number' => $acct,
                'bank_name' => $ctx['bank_name'] ?? '',
                'sell_ctx' => $this->sellCtxSnapshot($ctx),
                'need_oneclick_poll' => false,
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);

        error_log("Sendra sell v2: 1Click off-ramp PIN queued wa={$from} asset={$asset} amount={$amountStr}");
        $this->sendPinConfirmPrompt(
            $from,
            $pendingId,
            $this->buildPayoutConfirmBody($ctx, $name, $acct, $bankDisplay, $amountStr, $asset)
        );

        return StepResult::complete([
            'action' => 'sell_pin_queued_oneclick',
            'pending_id' => $pendingId,
        ]);
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     */
    private function queueStellarPin(string $from, array $userRow, array $ctx): StepResult
    {
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $asset = strtoupper((string) ($ctx['asset'] ?? 'USDC'));
        $name = (string) ($ctx['account_name'] ?? '');
        $acct = (string) ($ctx['account_number'] ?? '');
        $bankDisplay = (string) ($ctx['bank_name'] ?? '');

        $pendingId = $this->pendingTx->create([
            'user_phone' => $from,
            'tx_type' => 'offramp_stellar_centiiv',
            'amount' => $amountStr,
            'currency' => $asset,
            'recipient_address' => (string) ($userRow['stellar_address'] ?? ''),
            'human_description' => json_encode([
                'kind' => 'stellar_centiiv_offramp',
                'aptos_v2' => true,
                'chain' => 'stellar',
                'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
                'fiat' => $ctx['fiat'] ?? 'NGN',
                'target_receive_ngn' => (float) ($ctx['target_receive_ngn'] ?? 0),
                'receive_ngn_display' => $ctx['receive_ngn_display'] ?? null,
                'sendra_quoted_receive_ngn' => (float) ($ctx['sendra_quoted_receive_ngn'] ?? 0),
                'account_name' => $name,
                'account_number' => $acct,
                'bank_name' => $bankDisplay,
                'sell_ctx' => $this->sellCtxSnapshot($ctx),
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);
        $this->sendPinConfirmPrompt(
            $from,
            $pendingId,
            $this->buildPayoutConfirmBody($ctx, $name, $acct, $bankDisplay, $amountStr, $asset)
        );

        return StepResult::complete([
            'action' => 'sell_pin_queued_stellar',
            'pending_id' => $pendingId,
        ]);
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     */
    private function queueArcPin(string $from, array $userRow, array $ctx): StepResult
    {
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $asset = 'USDC';
        $name = (string) ($ctx['account_name'] ?? '');
        $acct = (string) ($ctx['account_number'] ?? '');
        $bankDisplay = (string) ($ctx['bank_name'] ?? '');

        $pendingId = $this->pendingTx->create([
            'user_phone' => $from,
            'tx_type' => 'offramp_arc_centiiv',
            'amount' => $amountStr,
            'currency' => $asset,
            'recipient_address' => (string) ($userRow['arc_address'] ?? ''),
            'human_description' => json_encode([
                'kind' => 'arc_centiiv_offramp',
                'aptos_v2' => true,
                'chain' => 'arc',
                'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
                'fiat' => $ctx['fiat'] ?? 'NGN',
                'target_receive_ngn' => (float) ($ctx['target_receive_ngn'] ?? 0),
                'receive_ngn_display' => $ctx['receive_ngn_display'] ?? null,
                'sendra_quoted_receive_ngn' => (float) ($ctx['sendra_quoted_receive_ngn'] ?? 0),
                'account_name' => $name,
                'account_number' => $acct,
                'bank_name' => $bankDisplay,
                'sell_ctx' => $this->sellCtxSnapshot($ctx),
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);
        $this->sendPinConfirmPrompt(
            $from,
            $pendingId,
            $this->buildPayoutConfirmBody($ctx, $name, $acct, $bankDisplay, $amountStr, $asset)
        );

        return StepResult::complete([
            'action' => 'sell_pin_queued_arc',
            'pending_id' => $pendingId,
        ]);
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     */
    private function queueDayaPin(string $from, array $userRow, array $ctx): StepResult
    {
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $asset = strtoupper((string) ($ctx['asset'] ?? 'USDC'));
        $name = (string) ($ctx['account_name'] ?? '');
        $acct = (string) ($ctx['account_number'] ?? '');
        $bankDisplay = (string) ($ctx['bank_name'] ?? '');
        $target = (float) ($ctx['target_receive_ngn'] ?? 0);
        $usePool = $target > 0 && SendraCentiivRampService::isIntermediaryNgnPayoutEnabled();

        $pendingId = $this->pendingTx->create([
            'user_phone' => $from,
            'tx_type' => 'offramp_daya_aptos',
            'amount' => $amountStr,
            'currency' => $asset,
            'recipient_address' => (string) ($userRow['aptos_address'] ?? ''),
            'human_description' => json_encode([
                'kind' => 'daya_aptos_offramp',
                'aptos_v2' => true,
                'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
                'fiat' => $ctx['fiat'] ?? 'NGN',
                'asset' => $asset,
                'crypto_amount' => $amountStr,
                'target_receive_ngn' => $target,
                'receive_ngn_display' => $ctx['receive_ngn_display'] ?? null,
                'sendra_quoted_receive_ngn' => (float) ($ctx['sendra_quoted_receive_ngn'] ?? 0),
                'sendra_intermediary_payout' => $usePool ? '1' : '0',
                'sendra_requested_ngn' => $usePool ? (string) round($target, 2) : '',
                'account_name' => $name,
                'account_number' => $acct,
                'bank_name' => $bankDisplay,
                'bank_code' => (string) ($ctx['bank_code'] ?? ''),
                'daya_bank_code' => (string) ($ctx['daya_bank_code'] ?? ''),
                'sendra_user_account_number' => $acct,
                'sendra_user_bank_code' => (string) ($ctx['bank_code'] ?? ''),
                'sendra_user_account_name' => $name,
                'sendra_user_bank_name' => $bankDisplay,
                'sendra_ledger_asset' => $asset,
                'sendra_ledger_amount' => $amountStr,
                'sell_ctx' => $this->sellCtxSnapshot($ctx),
            ], JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
        ]);

        $this->userModel->setPendingTransaction($from, $pendingId);
        $this->sendPinConfirmPrompt(
            $from,
            $pendingId,
            $this->buildPayoutConfirmBody($ctx, $name, $acct, $bankDisplay, $amountStr, $asset)
        );

        return StepResult::complete([
            'action' => 'sell_pin_queued_daya',
            'pending_id' => $pendingId,
        ]);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function attachDayaBankCode(array &$ctx): void
    {
        if (!empty($ctx['daya_bank_code'])) {
            return;
        }
        $name = trim((string) ($ctx['bank_name'] ?? ''));
        if ($name === '') {
            return;
        }
        $code = (new \App\Services\SendraDayaAptosService())->mapNameToDayaCode($name);
        if ($code !== null) {
            $ctx['daya_bank_code'] = $code;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stateToSellCtx(StepContext $ctx): array
    {
        return [
            'fiat' => (string) $ctx->stateValue('fiat', 'NGN'),
            'crypto_amount' => (string) $ctx->stateValue('crypto_amount', ''),
            'asset' => (string) $ctx->stateValue('asset', ''),
            'wallet_chain' => (string) $ctx->stateValue('wallet_chain', 'aptos'),
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function sellCtxSnapshot(array $ctx): array
    {
        $snapshot = [
            'fiat' => (string) ($ctx['fiat'] ?? 'NGN'),
            'account_number' => (string) ($ctx['account_number'] ?? ''),
            'bank_code' => (string) ($ctx['bank_code'] ?? ''),
            'account_name' => (string) ($ctx['account_name'] ?? ''),
            'bank_name' => (string) ($ctx['bank_name'] ?? ''),
            'centiiv_verify_data' => isset($ctx['centiiv_verify_data']) && is_array($ctx['centiiv_verify_data'])
                ? $ctx['centiiv_verify_data'] : null,
            'centiiv_banks_raw_row' => isset($ctx['centiiv_banks_raw_row']) && is_array($ctx['centiiv_banks_raw_row'])
                ? $ctx['centiiv_banks_raw_row'] : null,
            'target_receive_ngn' => (float) ($ctx['target_receive_ngn'] ?? 0),
            'receive_ngn_display' => (string) ($ctx['receive_ngn_display'] ?? ''),
            'sendra_quoted_receive_ngn' => (float) ($ctx['sendra_quoted_receive_ngn'] ?? 0),
            'send_naira_quick' => !empty($ctx['send_naira_quick']),
            'asset' => (string) ($ctx['asset'] ?? ''),
            'crypto_amount' => (string) ($ctx['crypto_amount'] ?? ''),
            'wallet_chain' => (string) ($ctx['wallet_chain'] ?? 'aptos'),
            'daya_bank_code' => (string) ($ctx['daya_bank_code'] ?? ''),
            'ramp' => (string) ($ctx['ramp'] ?? ''),
        ];
        $snapshot['sendra_resolved_requested_ngn'] = SendraCentiivRampService::resolveIntermediaryRequestedNgnFromPendingMeta(
            [],
            $snapshot
        );

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function buildPayoutConfirmBody(
        array $ctx,
        string $name,
        string $acct,
        string $bankDisplay,
        string $amountStr,
        string $asset
    ): string {
        $fiat = strtoupper((string) ($ctx['fiat'] ?? 'NGN'));
        $sym = SendraCentiivRampService::currencySymbol($fiat);

        if (!empty($ctx['send_naira_quick']) || ((float) ($ctx['target_receive_ngn'] ?? 0)) >= 100) {
            $recvNgn = (string) ($ctx['receive_ngn_display'] ?? '—');

            return "🔐 *Confirm payout*\n\n"
                . "*≈ {$sym}{$recvNgn}* payout to:\n*{$name}*\n`{$acct}` · *{$bankDisplay}*\n\n"
                . "Wallet debit: *{$amountStr} {$asset}*";
        }

        $rateLine = !empty($ctx['rate_display'])
            ? ("📊 *Official Sendra rate:* {$ctx['rate_display']}\n\n")
            : '';
        $est = (string) ($ctx['receive_ngn_display'] ?? '—');

        return "🔐 *Confirm payout*\n\n"
            . $rateLine
            . "You'll receive *≈ {$sym}{$est}* (estimate), paid directly to:\n"
            . "*{$name}*\n`{$acct}` · *{$bankDisplay}*\n\n"
            . "Wallet debit: *{$amountStr} {$asset}*";
    }

    private function sendPinConfirmPrompt(string $from, string $pendingId, string $bodyText): void
    {
        $row = $this->pendingTx->findById($pendingId);
        $expiresAt = $row ? strtotime((string) $row['expires_at']) : false;
        if ($expiresAt === false || $expiresAt <= 0) {
            $expiresAt = time() + 300;
        }

        $url = V2PinConfirmUrls::buildConfirmUrl($pendingId, $from, (int) $expiresAt);

        $this->whatsapp->sendCTAUrlButton(
            $from,
            $bodyText . "\n\n" .
                "🔐 Tap *Enter PIN* below to confirm securely.\n\n" .
                'Type *cancel* to abort.',
            'Enter PIN',
            $url
        );
    }

    /**
     * @param array<string, mixed> $sellCtx
     */
    private static function receiveNgnFromSellCtx(array $sellCtx): float
    {
        foreach (['sendra_quoted_receive_ngn', 'target_receive_ngn'] as $key) {
            $v = (float) ($sellCtx[$key] ?? 0);
            if ($v > 0) {
                return $v;
            }
        }

        $display = (string) ($sellCtx['receive_ngn_display'] ?? '');
        $digits = preg_replace('/[^\d.]/', '', str_replace(',', '', $display)) ?? '';

        return $digits !== '' ? (float) $digits : 0.0;
    }
}
