<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use App\Services\SendraCentiivRampService;
use App\Services\SendraOfframpBridge;
use App\Services\SendraOneClickService;
use App\Services\WhatsAppService;

/**
 * Live Centiiv quote for crypto-first USDC/USDT sells.
 */
final class QuoteService
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     */
    public function applyDirectQuote(
        string $from,
        array $userRow,
        array &$ctx,
        SendraCentiivRampService $agg
    ): bool {
        $asset = (string) ($ctx['asset'] ?? '');
        $chain = strtolower((string) ($ctx['wallet_chain'] ?? 'aptos'));

        if ($chain === 'arc') {
            return $this->applyArcDirectQuote($from, $ctx, $agg);
        }

        if ($chain === 'stellar') {
            return $this->applyStellarDirectQuote($from, $ctx, $agg);
        }

        if (\App\Services\SendraAptosRamp::usesDayaForAptos()) {
            return $this->applyDayaQuote($from, $ctx);
        }

        if ($asset === 'USDC') {
            return $this->applyDirectUsdcQuote($from, $ctx, $agg);
        }
        if ($asset === 'USDT') {
            return $this->applyDirectUsdtQuote($from, $userRow, $ctx, $agg);
        }

        $this->whatsapp->sendMessage($from, '⚠️ Something went wrong with your order. Type *sell* to start again.');

        return false;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function applyDirectUsdcQuote(string $from, array &$ctx, SendraCentiivRampService $agg): bool
    {
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $amount = (float) $amountStr;

        if ($amount < 0.01) {
            $this->whatsapp->sendMessage($from, '⚠️ Something went wrong with your order. Type *sell* to start again.');

            return false;
        }

        $toFiat = strtoupper((string) ($ctx['fiat'] ?? 'NGN'));
        $quote = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => $toFiat,
            'amount' => $amount,
        ]);

        if (!$this->handleQuoteFailure($from, $quote, $agg, $toFiat)) {
            return false;
        }

        $summary = SendraCentiivRampService::summarizeQuoteFiat($quote['data'], $toFiat, $amount);
        $grossFiat = (float) ($summary['receive_fiat'] ?? 0);
        if ($grossFiat <= 0) {
            $this->whatsapp->sendMessage($from, "❌ No rate available right now.\n\nTry again shortly.");

            return false;
        }

        $rate = $amount > 0 ? $grossFiat / $amount : 0.0;
        $sym = SendraCentiivRampService::currencySymbol($toFiat);
        $ctx['is_direct_payout'] = true;
        $ctx['receive_ngn_display'] = $summary['receive_fiat_display'];
        $ctx['sendra_quoted_receive_ngn'] = $grossFiat;
        $ctx['rate_ngn_per_usdc'] = $rate;
        $ctx['rate_display'] = $rate > 0 ? ($sym . number_format($rate, 2, '.', ',') . ' / USDC') : '';

        return true;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function applyDayaQuote(string $from, array &$ctx): bool
    {
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $amount = (float) $amountStr;
        $asset = strtoupper((string) ($ctx['asset'] ?? 'USDC'));
        if ($amount < 0.01) {
            $this->whatsapp->sendMessage($from, '⚠️ Something went wrong with your order. Type *sell* to start again.');

            return false;
        }
        $svc = new \App\Services\SendraDayaAptosService();
        $quoted = $svc->quoteSell($amount, $asset);
        if (empty($quoted['success'])) {
            $this->whatsapp->sendMessage(
                $from,
                (string) ($quoted['user_message'] ?? "❌ No rate available right now.\n\nTry again shortly.")
            );

            return false;
        }
        $recv = (float) ($quoted['receive_ngn'] ?? 0);
        $ctx['is_direct_payout'] = true;
        $ctx['receive_ngn_display'] = (string) ($quoted['receive_ngn_display'] ?? number_format($recv, 0, '.', ','));
        $ctx['sendra_quoted_receive_ngn'] = $recv;
        $ctx['rate_ngn_per_usdc'] = (float) ($quoted['rate'] ?? 0);
        $ctx['rate_display'] = (string) ($quoted['rate_hint'] ?? '');
        $ctx['fee_hint'] = (string) ($quoted['fee_hint'] ?? '');
        $ctx['daya_rate_id'] = (string) ($quoted['rate_id'] ?? '');
        $ctx['daya_rate'] = (float) ($quoted['rate'] ?? 0);
        $ctx['ramp'] = 'daya';

        return true;
    }

    /**
     * Native Stellar USDC / USDT0 → NGN (no 1Click).
     *
     * @param array<string, mixed> $ctx
     */
    private function applyStellarDirectQuote(string $from, array &$ctx, SendraCentiivRampService $agg): bool
    {
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $amount = (float) $amountStr;
        $asset = (string) ($ctx['asset'] ?? 'USDC');
        $fromAsset = \AptosV2\Support\StellarStable::centiivAsset($asset);

        if ($amount < 0.01) {
            $this->whatsapp->sendMessage($from, '⚠️ Something went wrong with your order. Type *sell* to start again.');

            return false;
        }

        $toFiat = strtoupper((string) ($ctx['fiat'] ?? 'NGN'));
        $quote = $agg->getQuote([
            'fromAsset' => $fromAsset,
            'toAsset' => $toFiat,
            'amount' => $amount,
            'network' => 'STELLAR',
        ]);

        if (!$this->handleQuoteFailure($from, $quote, $agg, $toFiat)) {
            return false;
        }

        $summary = SendraCentiivRampService::summarizeQuoteFiat($quote['data'], $toFiat, $amount);
        $grossFiat = (float) ($summary['receive_fiat'] ?? 0);
        if ($grossFiat <= 0) {
            $this->whatsapp->sendMessage($from, "❌ No rate available right now.\n\nTry again shortly.");

            return false;
        }

        $rate = $amount > 0 ? $grossFiat / $amount : 0.0;
        $sym = SendraCentiivRampService::currencySymbol($toFiat);
        $label = \AptosV2\Support\StellarStable::display($asset);
        $ctx['is_direct_payout'] = true;
        $ctx['receive_ngn_display'] = $summary['receive_fiat_display'];
        $ctx['sendra_quoted_receive_ngn'] = $grossFiat;
        $ctx['rate_ngn_per_usdc'] = $rate;
        $ctx['rate_display'] = $rate > 0 ? ($sym . number_format($rate, 2, '.', ',') . ' / ' . $label) : '';

        return true;
    }

    /**
     * Native Arc USDC → NGN (no 1Click).
     *
     * @param array<string, mixed> $ctx
     */
    private function applyArcDirectQuote(string $from, array &$ctx, SendraCentiivRampService $agg): bool
    {
        $amount = (float) ($ctx['crypto_amount'] ?? 0);
        if (strtoupper((string) ($ctx['asset'] ?? 'USDC')) !== 'USDC' || $amount < 0.01) {
            $this->whatsapp->sendMessage($from, '⚠️ Arc cash-out is USDC only. Type *sell* to start again.');

            return false;
        }

        $toFiat = strtoupper((string) ($ctx['fiat'] ?? 'NGN'));
        $quote = $agg->getQuote([
            'fromAsset' => 'USDC',
            'toAsset' => $toFiat,
            'amount' => $amount,
            'network' => 'ARC',
        ]);

        if (!$this->handleQuoteFailure($from, $quote, $agg, $toFiat)) {
            return false;
        }

        $summary = SendraCentiivRampService::summarizeQuoteFiat($quote['data'], $toFiat, $amount);
        $grossFiat = (float) ($summary['receive_fiat'] ?? 0);
        if ($grossFiat <= 0) {
            $this->whatsapp->sendMessage($from, "❌ No rate available right now.\n\nTry again shortly.");

            return false;
        }

        $rate = $amount > 0 ? $grossFiat / $amount : 0.0;
        $sym = SendraCentiivRampService::currencySymbol($toFiat);
        $ctx['is_direct_payout'] = true;
        $ctx['receive_ngn_display'] = $summary['receive_fiat_display'];
        $ctx['sendra_quoted_receive_ngn'] = $grossFiat;
        $ctx['rate_ngn_per_usdc'] = $rate;
        $ctx['rate_display'] = $rate > 0 ? ($sym . number_format($rate, 2, '.', ',') . ' / USDC') : '';

        return true;
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     */
    private function applyDirectUsdtQuote(
        string $from,
        array $userRow,
        array &$ctx,
        SendraCentiivRampService $agg
    ): bool {
        $amountStr = (string) ($ctx['crypto_amount'] ?? '');
        $amount = (float) $amountStr;

        if ($amount < 0.01 || !Guard::oneclickCentiivRampConfigured()) {
            $this->whatsapp->sendMessage($from, '⚠️ Something went wrong with your order. Type *sell* to start again.');

            return false;
        }

        $refundAptos = trim((string) ($userRow['aptos_address'] ?? ''));
        if ($refundAptos === '') {
            $this->whatsapp->sendMessage($from, '❌ Missing Aptos address. Contact support.');

            return false;
        }

        $centiivFromAsset = 'USDC';
        $originUsdt = trim((string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near'));
        $deadlineIso = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60);
        $amountSmallest = SendraOneClickService::cryptoAmountToSmallestUnits($amountStr, 6);
        $oneClick = new SendraOneClickService();
        $dryBody = SendraOneClickService::buildAptosToOfframpUsdcQuoteBody(
            $originUsdt,
            $amountSmallest,
            SendraOfframpBridge::dryRecipient(),
            $refundAptos,
            $deadlineIso,
            true
        );
        $dryRes = $oneClick->requestQuote($dryBody);
        if (!$dryRes['success']) {
            $err = (string) ($dryRes['error'] ?? 'bridge quote failed');
            error_log("Sendra sell v2: USDT bridge quote failed {$from}: {$err}");
            $this->whatsapp->sendMessage(
                $from,
                '❌ Could not get a rate right now. Try again in a few minutes.'
            );

            return false;
        }
        $parsedDry = SendraOneClickService::parseQuoteResponse($dryRes['data']);
        $estUsdc = (float) ($parsedDry['amount_out_formatted'] ?? 0);
        if ($estUsdc < 0.01) {
            $this->whatsapp->sendMessage($from, '❌ Bridge estimate too small for a rate quote. Try a larger amount.');

            return false;
        }
        $ctx['oneclick_dry_quote_data'] = $dryRes['data'];
        $centiivQuoteAmount = $estUsdc;

        $toFiat = strtoupper((string) ($ctx['fiat'] ?? 'NGN'));
        $quote = $agg->getQuote([
            'fromAsset' => $centiivFromAsset,
            'toAsset' => $toFiat,
            'amount' => $centiivQuoteAmount,
        ]);

        if (!$this->handleQuoteFailure($from, $quote, $agg, $toFiat)) {
            return false;
        }

        $summary = SendraCentiivRampService::summarizeQuoteFiat($quote['data'], $toFiat, $centiivQuoteAmount);
        $grossFiat = (float) ($summary['receive_fiat'] ?? 0);
        if ($grossFiat <= 0) {
            $this->whatsapp->sendMessage($from, "❌ No rate available right now.\n\nTry again shortly.");

            return false;
        }

        $rate = $centiivQuoteAmount > 0 ? $grossFiat / $centiivQuoteAmount : 0.0;
        $sym = SendraCentiivRampService::currencySymbol($toFiat);
        $ctx['is_direct_payout'] = true;
        $ctx['receive_ngn_display'] = $summary['receive_fiat_display'];
        $ctx['sendra_quoted_receive_ngn'] = $grossFiat;
        $ctx['rate_ngn_per_usdc'] = $rate;
        $ctx['rate_display'] = $rate > 0 ? ($sym . number_format($rate, 2, '.', ',') . ' / USDC') : '';

        return true;
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function handleQuoteFailure(string $from, array $quote, SendraCentiivRampService $agg, string $fiat = 'NGN'): bool
    {
        if ($quote['success']) {
            return true;
        }

        $err = (string) ($quote['error'] ?? 'Quote failed');
        error_log("Centiiv quote failed for {$from}: {$err} " . json_encode($quote['data'] ?? null));
        $this->whatsapp->sendMessage($from, $agg->quoteFailureUserMessage($err, $fiat));

        return false;
    }
}
