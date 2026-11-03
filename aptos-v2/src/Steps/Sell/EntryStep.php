<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\AptosBalanceService;

use AptosV2\Support\PhoneNormalizer;
use AptosV2\Support\WalletChoice;

/**
 * Sell flow entry — `sell` or `sell 5 USDC` / `sell 1 USDT` one-liner.
 */
final class EntryStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($block = Guard::entryBlock($ctx, $ctx->whatsapp())) {
            return $block;
        }

        $fiat = PhoneNormalizer::resolveFiat($ctx->from);

        $oneLiner = CryptoIntentParser::parseOneLiner($ctx->message);
        if ($oneLiner !== null) {
            return $this->beginCryptoSell($ctx, $oneLiner['amount'], $oneLiner['asset'], $fiat);
        }

        if ($ctx->messageLower === 'sell' || str_starts_with($ctx->messageLower, 'sell_fiat_')) {
            $bankDesc = match ($fiat) {
                'KES' => 'M-Pesa or bank',
                'XOF' => 'MoMo account',
                default => 'bank',
            };
            $exampleMsg = match ($fiat) {
                'KES' => '• Reply format: *0758901726 Mpesa*',
                'XOF' => '• XOF payout: *send 5000 xof to 22890123456*',
                default => '• Naira payout: *send 1000 naira to 0123456789 GTBank*',
            };

            $this->reply(
                $ctx,
                "Cash out to your {$bankDesc}.\n\n" .
                "• Crypto amount: *5 USDC* or *1 USDT*\n" .
                "{$exampleMsg}\n\n" .
                'Type *cancel* to stop.'
            );

            return StepResult::transition('sell', 'amount', ['fiat' => $fiat], ['action' => 'sell_amount_prompt']);
        }

        $this->reply(
            $ctx,
            "Cash out needs a clearer amount.\n\n" .
            "Try *sell 5 USDC*, *sell 1 USDT*, or `send 1000 naira to 0123456789 GTBank`."
        );

        return StepResult::complete(['action' => 'sell_unknown']);
    }

    private function beginCryptoSell(StepContext $ctx, string $amountStr, string $asset, string $fiat = 'NGN'): StepResult
    {
        WalletChoice::prompt($ctx, "Cash out *{$amountStr} {$asset}* from which wallet?");

        return StepResult::transition('sell', 'wallet', [
            'fiat' => $fiat,
            'crypto_amount' => $amountStr,
            'asset' => $asset,
        ], ['action' => 'sell_wallet_prompt']);
    }
}
