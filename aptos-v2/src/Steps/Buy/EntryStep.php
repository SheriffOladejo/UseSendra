<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use App\Services\SendraDayaPolymarketService;
use AptosV2\Services\Buy\QuoteService;
use AptosV2\Support\WalletChoice;
use AptosV2\Steps\Kyc\Gate as KycGate;

/**
 * Buy flow entry — `buy`, `buy 5000`, `buy 5 USDC`.
 */
final class EntryStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($block = Guard::entryBlock($ctx, $ctx->whatsapp())) {
            return $block;
        }

        $parsed = IntentParser::parseEntry($ctx->message, $ctx->messageLower);
        if ($parsed === null) {
            $this->reply(
                $ctx,
                "Try:\n• *buy 5000* (spend Naira)\n• *buy 5 USDC* (receive crypto)\n• *buy* then enter an amount"
            );

            return StepResult::complete(['action' => 'buy_usage']);
        }

        if ($parsed['type'] === 'bare') {
            $this->reply(
                $ctx,
                "Buy USDT or USDC with Naira.\n\n" .
                "• Naira amount: *5000* or *10000 naira*\n" .
                "• Crypto amount: *5 USDC* or *10 USDT*\n\n" .
                '*cancel* stops.'
            );

            return StepResult::transition('buy', 'await_amount', ['fiat' => 'NGN'], ['action' => 'buy_ngn_amount']);
        }

        if ($parsed['type'] === 'naira') {
            $amt = (float) $parsed['pay_ngn'];
            if ($kycBlock = KycGate::blockIfNeeded($ctx, $amt, [
                'flow' => 'buy',
                'step' => 'await_asset',
                'fiat' => 'NGN',
                'pay_ngn' => $amt,
            ])) {
                return $kycBlock;
            }
            $this->buttons(
                $ctx,
                "You'll pay *≈ ₦" . number_format($amt, 0, '.', ',') . "*.\nWhich token should land in your wallet?",
                [
                    'buy_pick_usdt' => 'USDT',
                    'buy_pick_usdc' => 'USDC',
                ]
            );

            return StepResult::transition('buy', 'await_asset', [
                'fiat' => 'NGN',
                'pay_ngn' => $amt,
            ], ['action' => 'buy_pick_asset']);
        }

        $activeStep = (string) ($ctx->state['step'] ?? '');
        if ($activeStep === 'quote_pending') {
            $target = (float) $parsed['target_crypto'];
            $asset = (string) $parsed['asset'];
            $this->reply(
                $ctx,
                "⏳ A rate is still loading.\n\nType *cancel* first, then try *buy {$target} {$asset}* again."
            );

            return StepResult::stay(['action' => 'buy_quote_pending_cancel_first']);
        }

        WalletChoice::prompt(
            $ctx,
            "You'll buy *{$parsed['asset']}*. Which wallet should receive it?",
            [],
            in_array(strtoupper((string) $parsed['asset']), ['USDC', 'USDT'], true),
            true,
            (new SendraDayaPolymarketService())->isConfigured()
        );

        return StepResult::transition('buy', 'await_wallet', [
            'fiat' => 'NGN',
            'asset' => (string) $parsed['asset'],
            'target_crypto' => (float) $parsed['target_crypto'],
            'quote_mode' => 'crypto_target',
        ], ['action' => 'buy_wallet_prompt']);
    }
}
