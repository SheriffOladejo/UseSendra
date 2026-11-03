<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

use App\Services\SendraCentiivRampService;
use App\Services\SendraOfframpBridge;
use App\Services\SendraOneClickService;

/**
 * 1Click off-ramp: validate live quotes after PIN, open Centiiv order only when gates pass,
 * then bind a final 1Click quote to the real Centiiv pay-in address before Aptos debit.
 */
final class OneClickOfframpSetupService
{
    public function __construct(
        private SellIntermediaryPool $pool,
        private CentiivOfframpOrderService $centiivOrders,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pending
     * @return array{
     *   success: bool,
     *   deposit_address: ?string,
     *   deposit_memo: ?string,
     *   correlation_id: ?string,
     *   order_stellar: ?string,
     *   meta_patch: array<string, mixed>,
     *   user_message: ?string
     * }
     */
    public function setupAfterPin(
        string $from,
        array $user,
        array $pending,
        SendraCentiivRampService $agg
    ): array {
        $meta = json_decode((string) ($pending['human_description'] ?? '{}'), true) ?: [];
        $fail = static function (string $msg): array {
            return [
                'success' => false,
                'deposit_address' => null,
                'deposit_memo' => null,
                'correlation_id' => null,
                'order_stellar' => null,
                'meta_patch' => [],
                'user_message' => $msg,
            ];
        };

        if (empty($meta['centiiv_order_deferred'])
            && !empty($meta['centiiv_order_id'])
            && !empty($meta['oneclick_correlation_id'])
            && trim((string) ($pending['recipient_address'] ?? '')) !== '') {
            return [
                'success' => true,
                'deposit_address' => (string) $pending['recipient_address'],
                'deposit_memo' => isset($meta['oneclick_deposit_memo']) && is_string($meta['oneclick_deposit_memo'])
                    ? $meta['oneclick_deposit_memo'] : null,
                'correlation_id' => (string) ($meta['oneclick_correlation_id'] ?? ''),
                'order_stellar' => (string) ($meta['centiiv_payin_address'] ?? $meta['centiiv_stellar_payin'] ?? ''),
                'meta_patch' => [],
                'user_message' => null,
            ];
        }

        $sellCtx = is_array($meta['sell_ctx'] ?? null) ? $meta['sell_ctx'] : [];
        if ($sellCtx === []) {
            $sellCtx = [
                'account_number' => (string) ($meta['account_number'] ?? ''),
                'bank_code' => '',
                'account_name' => (string) ($meta['account_name'] ?? ''),
                'bank_name' => (string) ($meta['bank_name'] ?? ''),
                'target_receive_ngn' => (float) ($meta['target_receive_ngn'] ?? 0),
                'receive_ngn_display' => (string) ($meta['receive_ngn_display'] ?? ''),
            ];
        }

        $asset = (string) ($pending['currency'] ?? $sellCtx['asset'] ?? 'USDC');
        $amountStr = (string) ($pending['amount'] ?? $sellCtx['crypto_amount'] ?? '');
        $refundAptos = trim((string) ($user['aptos_address'] ?? ''));
        if ($refundAptos === '') {
            return $fail('❌ Missing Aptos address on your account. Contact support.');
        }

        $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        $originUsdc = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near'));
        $originAsset = $asset === 'USDC' ? $originUsdc : $originUsdt;
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $amountSmallest = SendraOneClickService::cryptoAmountToSmallestUnits($amountStr, 6);
        $oneClick = new SendraOneClickService();

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
            error_log("Sendra 1Click dry quote at PIN (v2) failed {$from}: {$err} " . json_encode($quoteDryRes['data']));

            return $fail("❌ Could not get a quote right now.\n\nYour wallet balance is unchanged. Try again in a minute.");
        }
        $parsedDry = SendraOneClickService::parseQuoteResponse($quoteDryRes['data']);
        if ($parsedDry['correlation_id'] === null) {
            return $fail('❌ Quote response incomplete. Your wallet balance is unchanged.');
        }
        $usdcDrySmallest = (string) ($parsedDry['amount_out'] ?? '0');
        $centiivUsdcAmount = SendraOfframpBridge::quotedUsdcOut($parsedDry);
        if ($centiivUsdcAmount <= 0) {
            return $fail('❌ Invalid quoted USDC amount. Your wallet balance is unchanged.');
        }

        $targetNgn = $this->pool->resolveRequestedNgn($meta, $sellCtx);
        $toleranceNgn = (float) ($_ENV['SENDRA_SELL_TARGET_NGN_TOLERANCE'] ?? getenv('SENDRA_SELL_TARGET_NGN_TOLERANCE') ?: 1);
        if ($toleranceNgn < 1) {
            $toleranceNgn = 1;
        }
        $checkOvershoot = $this->pool->isFixedNgnTarget($meta, $sellCtx);
        $useIntermediary = SendraCentiivRampService::isIntermediaryNgnPayoutEnabled()
            && $this->pool->isFixedNgnTarget($meta, $sellCtx);

        if ($useIntermediary && $targetNgn > 0) {
            $poolErr = $this->pool->assertPoolNgnFromStellarUsdc(
                $agg,
                $centiivUsdcAmount,
                $targetNgn,
                $toleranceNgn,
                false
            );
            if ($poolErr !== null) {
                return $fail($poolErr);
            }
        }

        $slippageBps = (int) ($_ENV['SENDRA_ONECLICK_CENTIIV_SLIPPAGE_BPS'] ?? getenv('SENDRA_ONECLICK_CENTIIV_SLIPPAGE_BPS') ?: 200);
        if ($slippageBps < 0 || $slippageBps > 5000) {
            $slippageBps = 200;
        }

        // Pre-flight live quote check (avoid creating Centiiv order when rate/slippage/pool coverage would fail).
        $stellarPlaceholderRecipient = SendraOfframpBridge::placeholderRecipient();
        $quoteBodyLivePreflight = SendraOneClickService::buildAptosToOfframpUsdcQuoteBody(
            $originAsset,
            $amountSmallest,
            $stellarPlaceholderRecipient,
            $refundAptos,
            $deadlineIso,
            false
        );
        $quoteResPreflight = $oneClick->requestQuote($quoteBodyLivePreflight);
        if (!$quoteResPreflight['success']) {
            error_log("Sendra 1Click preflight live quote at PIN (v2) failed {$from}: " . ($quoteResPreflight['error'] ?? ''));

            return $fail("❌ Could not start this payout right now.\n\nYour wallet balance is unchanged. Try again in a few minutes.");
        }

        $parsedPreflight = SendraOneClickService::parseQuoteResponse($quoteResPreflight['data']);
        if ($parsedPreflight['deposit_address'] === null || $parsedPreflight['correlation_id'] === null) {
            return $fail('❌ Quote response incomplete. Your wallet balance is unchanged.');
        }

        $usdcLiveSmallestPreflight = (string) ($parsedPreflight['amount_out'] ?? '0');
        $liveUsdcAmountPreflight = SendraOfframpBridge::quotedUsdcOut($parsedPreflight);
        if ($liveUsdcAmountPreflight <= 0) {
            return $fail('❌ Invalid live USDC quote. Your wallet balance is unchanged.');
        }

        if ($useIntermediary) {
            $poolErr = $this->pool->assertPoolNgnFromStellarUsdc(
                $agg,
                $liveUsdcAmountPreflight,
                $targetNgn,
                $toleranceNgn,
                $checkOvershoot
            );
            if ($poolErr !== null) {
                return $fail($poolErr);
            }
        }

        if (ctype_digit($usdcDrySmallest) && ctype_digit($usdcLiveSmallestPreflight) && bccomp($usdcDrySmallest, '0', 0) > 0) {
            $minLive = bcdiv(bcmul($usdcDrySmallest, (string) (10000 - $slippageBps), 0), '10000', 0);
            if (bccomp($usdcLiveSmallestPreflight, $minLive, 0) < 0) {
                return $fail('❌ The live rate moved too much. Your wallet balance is unchanged — try *sell* again.');
            }
        }

        // Second live quote gate (placeholder recipient). Recipient-specific routing can change
        // quoted USDC out, so slippage/pool checks run here before any Centiiv order is opened.
        $quoteBodyLiveGate = SendraOneClickService::buildAptosToOfframpUsdcQuoteBody(
            $originAsset,
            $amountSmallest,
            $stellarPlaceholderRecipient,
            $refundAptos,
            $deadlineIso,
            false
        );
        $quoteResLiveGate = $oneClick->requestQuote($quoteBodyLiveGate);
        if (!$quoteResLiveGate['success']) {
            error_log("Sendra 1Click confirmatory live quote at PIN (v2) failed {$from}: " . ($quoteResLiveGate['error'] ?? ''));

            return $fail("❌ Could not start this payout right now.\n\nYour wallet balance is unchanged. Try again in a few minutes.");
        }

        $parsedLiveGate = SendraOneClickService::parseQuoteResponse($quoteResLiveGate['data']);
        if ($parsedLiveGate['deposit_address'] === null || $parsedLiveGate['correlation_id'] === null) {
            return $fail('❌ Quote response incomplete. Your wallet balance is unchanged.');
        }

        $usdcLiveSmallestGate = (string) ($parsedLiveGate['amount_out'] ?? '0');
        $liveUsdcAmountGate = SendraOfframpBridge::quotedUsdcOut($parsedLiveGate);
        if ($liveUsdcAmountGate <= 0) {
            return $fail('❌ Invalid live USDC quote. Your wallet balance is unchanged.');
        }

        if ($useIntermediary) {
            $poolErr = $this->pool->assertPoolNgnFromStellarUsdc(
                $agg,
                $liveUsdcAmountGate,
                $targetNgn,
                $toleranceNgn,
                $checkOvershoot
            );
            if ($poolErr !== null) {
                return $fail($poolErr);
            }
        }

        if (ctype_digit($usdcDrySmallest) && ctype_digit($usdcLiveSmallestGate) && bccomp($usdcDrySmallest, '0', 0) > 0) {
            $minLive = bcdiv(bcmul($usdcDrySmallest, (string) (10000 - $slippageBps), 0), '10000', 0);
            if (bccomp($usdcLiveSmallestGate, $minLive, 0) < 0) {
                return $fail('❌ The live rate moved too much. Your wallet balance is unchanged — try *sell* again.');
            }
        }

        $opened = $this->centiivOrders->createFromMeta(
            $from,
            $sellCtx,
            'USDC',
            $centiivUsdcAmount,
            'Sendra WhatsApp sell ' . $from . ' (1Click dry ' . $parsedDry['correlation_id'] . ')',
            [
                'sendra_oneclick_correlation_id' => $parsedDry['correlation_id'],
                'sendra_oneclick_quote_phase' => 'dry_pool_pricing',
                'sendra_ledger_asset' => $asset,
                'sendra_ledger_amount' => $amountStr,
            ],
            $agg
        );
        if (!$opened['success']) {
            return $fail("❌ Could not start this payout right now.\n\nYour wallet balance is unchanged. Please try again in a few minutes.");
        }

        $orderStellar = $this->centiivOrders->resolvePayin($opened['data'], $from);
        if ($orderStellar === null) {
            error_log('Sendra 1Click post-PIN (v2): no Centiiv pay-in for ' . $from . ' raw=' . json_encode($opened['data']));

            return $fail('❌ Could not start this payout. Your wallet balance is unchanged. Contact support@usesendra.com');
        }

        // Binding live quote: real Centiiv pay-in for Aptos deposit correlation (rate already gated).
        $quoteBodyLive = SendraOneClickService::buildAptosToOfframpUsdcQuoteBody(
            $originAsset,
            $amountSmallest,
            $orderStellar,
            $refundAptos,
            $deadlineIso,
            false
        );
        $quoteRes = $oneClick->requestQuote($quoteBodyLive);
        if (!$quoteRes['success']) {
            error_log("Sendra 1Click binding live quote at PIN (v2) failed {$from}: " . ($quoteRes['error'] ?? ''));

            return $fail("❌ Could not start this payout right now.\n\nYour wallet balance is unchanged. Try again in a few minutes.");
        }

        $parsed = SendraOneClickService::parseQuoteResponse($quoteRes['data']);
        if ($parsed['deposit_address'] === null || $parsed['correlation_id'] === null) {
            return $fail('❌ Quote response incomplete. Your wallet balance is unchanged.');
        }

        $usdcLiveSmallest = (string) ($parsed['amount_out'] ?? '0');
        $liveUsdcAmount = SendraOfframpBridge::quotedUsdcOut($parsed);
        if ($liveUsdcAmount <= 0) {
            return $fail('❌ Invalid live USDC quote. Your wallet balance is unchanged.');
        }

        if ($useIntermediary) {
            $poolErr = $this->pool->assertPoolNgnFromStellarUsdc(
                $agg,
                $liveUsdcAmount,
                $targetNgn,
                $toleranceNgn,
                $checkOvershoot
            );
            if ($poolErr !== null) {
                return $fail($poolErr);
            }
        }

        $metaPatch = [
            'centiiv_order_deferred' => false,
            'centiiv_order_id' => $opened['order_id'],
            'centiiv_order_id_hex' => $opened['order_id_hex'],
            'sendra_payout_reference' => $opened['payout_ref'],
            'centiiv_stellar_payin' => $orderStellar,
            'centiiv_payin_address' => $orderStellar,
            'centiiv_payin_network' => SendraOfframpBridge::centiivNetwork(),
            'centiiv_usdc_amount' => $centiivUsdcAmount,
            'oneclick_correlation_id' => $parsed['correlation_id'],
            'oneclick_correlation_id_dry' => $parsedDry['correlation_id'],
            'oneclick_deposit_memo' => $parsed['deposit_memo'],
            'oneclick_stellar_usdc_out' => $parsed['amount_out_formatted'],
            'oneclick_quote_signature' => $parsed['signature'],
        ];

        return [
            'success' => true,
            'deposit_address' => (string) $parsed['deposit_address'],
            'deposit_memo' => isset($parsed['deposit_memo']) && is_string($parsed['deposit_memo']) ? $parsed['deposit_memo'] : null,
            'correlation_id' => (string) $parsed['correlation_id'],
            'order_stellar' => $orderStellar,
            'meta_patch' => $metaPatch,
            'user_message' => null,
        ];
    }
}
