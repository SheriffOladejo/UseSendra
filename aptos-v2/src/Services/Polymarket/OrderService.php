<?php

declare(strict_types=1);

namespace AptosV2\Services\Polymarket;

use App\Services\SendraDayaPolymarketService;
use App\Services\WhatsAppService;
use AptosV2\Persistence\OnrampPendingRepository;

final class OrderService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private OnrampPendingRepository $onrampPending,
    ) {
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     * @return array{outcome: string, flow?: string, step?: string, patch?: array<string, mixed>, meta?: array<string, mixed>}
     */
    public function quoteAndPrompt(string $from, array $ctx): array
    {
        $svc = new SendraDayaPolymarketService();
        $wait = !empty($ctx['from_buy']) ? '⏳ Getting a Polygon buy rate…' : '⏳ Getting a Polymarket funding rate…';
        $this->whatsapp->sendMessage($from, $wait);
        $quote = $svc->quoteFromContext($ctx);
        if (!$quote['success']) {
            $this->whatsapp->sendMessage($from, (string) ($quote['user_message'] ?? '❌ Quote failed. Try again.'));

            return ['outcome' => 'complete', 'meta' => ['action' => 'polymarket_quote_failed']];
        }

        $asset = $svc->settlementAsset();
        $chain = $svc->settlementChain();
        $addr = (string) ($ctx['polygon_address'] ?? '');
        $short = strlen($addr) === 42 ? (substr($addr, 0, 8) . '…' . substr($addr, -6)) : $addr;
        $payDisplay = number_format((float) $quote['pay_ngn'], 2, '.', ',');
        $feeLine = !empty($quote['fee_hint']) ? ("\n" . $quote['fee_hint']) : '';
        $rateLine = !empty($quote['rate_hint']) ? ("\n" . $quote['rate_hint']) : '';
        $fromBuy = !empty($ctx['from_buy']);
        $title = $fromBuy
            ? "🛒 *Buy {$asset} on Polygon*"
            : "🎯 *Fund Polymarket ({$asset} on {$chain})*";
        $note = $fromBuy
            ? "Sendra does not hold a Polygon wallet — double-check this address. Polymarket deposit addresses work; USDT converts to pUSD there."
            : "USDT is converted to pUSD on Polymarket after it arrives. Sendra does not hold a Polygon wallet — double-check this address.";

        $this->whatsapp->sendButtonMessage(
            $from,
            "{$title}\n\n" .
            "Pay *₦{$payDisplay}* to send *≈ {$quote['receive_crypto_display']} {$asset}* to:\n`{$short}`\n" .
            "{$feeLine}{$rateLine}\n\n" .
            "{$note}\n\n" .
            'Continue to get a *virtual bank account*?',
            [
                'polymarket_yes' => '✅ Yes',
                'polymarket_change' => '✏️ Different amount',
            ]
        );

        return [
            'outcome' => 'transition',
            'flow' => 'polymarket',
            'step' => 'await_quote_confirm',
            'patch' => [
                'fiat' => 'NGN',
                'quote_mode' => (string) $quote['quote_mode'],
                'pay_ngn' => $quote['pay_ngn'],
                'target_crypto' => $ctx['target_crypto'] ?? null,
                'receive_crypto_display' => $quote['receive_crypto_display'],
                'daya_rate_id' => $quote['rate_id'],
                'daya_rate_expires_at' => $quote['expires_at'] ?? '',
                'polygon_address' => $addr,
                'asset' => $asset,
                'chain' => $chain,
                'from_buy' => $fromBuy,
                'aptos_v2' => true,
                'origin_whatsapp_phone_id' => $this->whatsapp->getPhoneNumberId(),
            ],
            'meta' => ['action' => 'polymarket_quote'],
        ];
    }

    /**
     * @param array<string, mixed> $userRow
     * @param array<string, mixed> $ctx
     * @return array{outcome: string, flow?: string, step?: string, patch?: array<string, mixed>, meta?: array<string, mixed>}
     */
    public function createAndSendBank(string $from, array $userRow, array $ctx): array
    {
        $payNgn = (float) ($ctx['pay_ngn'] ?? 0);
        $kyc = new \App\Services\SendraKycService();
        if ($payNgn > 0 && !$kyc->canTransactNgn($userRow, $payNgn)) {
            $retry = !empty($ctx['from_buy']) ? '*buy*' : '*polymarket*';
            $this->whatsapp->sendMessage(
                $from,
                "This amount is above your current Sendra KYC limit. Complete verification, then type {$retry} again."
            );

            return ['outcome' => 'complete', 'meta' => ['action' => 'polymarket_kyc_blocked']];
        }

        $svc = new SendraDayaPolymarketService();
        $created = $svc->createOnramp($from, $userRow, $ctx);
        if (!$created['success']) {
            $this->whatsapp->sendMessage($from, (string) ($created['user_message'] ?? '❌ Could not open payment.'));

            return [
                'outcome' => 'transition',
                'flow' => 'polymarket',
                'step' => 'await_quote_confirm',
                'patch' => $ctx,
                'meta' => ['action' => 'polymarket_order_failed'],
            ];
        }

        $meta = is_array($created['meta'] ?? null) ? $created['meta'] : [];
        $meta['aptos_v2'] = true;
        $meta['origin_whatsapp_phone_id'] = $this->whatsapp->getPhoneNumberId();
        $meta['user_phone'] = $from;

        $this->onrampPending->store([
            'user_phone' => $from,
            'tx_type' => 'onramp_daya_polymarket',
            'amount' => (string) ($meta['receive_crypto_display'] ?? ''),
            'currency' => (string) ($meta['destination_asset'] ?? 'USDT'),
            'recipient_address' => (string) ($meta['destination_address'] ?? ''),
            'human_description' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);

        $instruct = (string) ($created['instruct_ngn'] ?? $meta['instruct_ngn'] ?? '');
        $payDisplay = $instruct !== ''
            ? number_format((float) $instruct, 2, '.', ',')
            : number_format((float) ($ctx['pay_ngn'] ?? 0), 2, '.', ',');
        $recv = (string) ($meta['receive_crypto_display'] ?? $ctx['receive_crypto_display'] ?? '—');
        $asset = (string) ($meta['destination_asset'] ?? 'USDT');
        $orderId = (string) ($created['funding_account_id'] ?? '');
        $bank = is_array($created['bank'] ?? null) ? $created['bank'] : null;

        if ($bank === null) {
            $this->whatsapp->sendMessage(
                $from,
                "✅ *Payment opened*\n\nRef `{$orderId}`\nPay *exactly ₦{$payDisplay}*.\nYou should receive *≈ {$recv} {$asset}* on Polygon.\n\nBank details were not returned yet — we'll retry shortly or email support@usesendra.com"
            );

            return ['outcome' => 'complete', 'meta' => ['action' => 'polymarket_order_no_bank']];
        }

        $payTitle = !empty($ctx['from_buy'])
            ? '🏦 *Pay to receive USDT on Polygon*'
            : '🏦 *Pay to fund Polymarket*';
        $this->whatsapp->sendMessage(
            $from,
            "{$payTitle}\n\n" .
            "Transfer *exactly ₦{$payDisplay}* to:\n\n" .
            "*{$bank['account_name']}*\n" .
            "`{$bank['account_number']}`\n" .
            "*{$bank['bank_name']}*\n\n" .
            "Ref: `{$orderId}`\n" .
            "You receive: *≈ {$recv} {$asset}* on Polygon → the address you pasted\n\n" .
            "⏱ Pay within *30 minutes* using this exact amount. We'll notify you when USDT is sent."
        );

        return ['outcome' => 'complete', 'meta' => ['action' => 'polymarket_bank_sent']];
    }
}
