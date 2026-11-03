<?php

declare(strict_types=1);

namespace AptosV2\Steps\Sell;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\WalletChoice;

/**
 * Collects crypto amount when user typed bare `sell`.
 */
final class AmountStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($this->wantsCancel($ctx)) {
            $this->reply($ctx, "Cancelled. Type *menu* for options.");

            return StepResult::complete(['action' => 'sell_cancelled']);
        }

        $parsed = CryptoIntentParser::parseAmountLine($ctx->message);
        if ($parsed === null) {
            $this->reply(
                $ctx,
                "Reply with amount and token, e.g. *5 USDC* or *1 USDT*.\n" .
                "Or cash out Naira with:\n`send 1000 naira to 0123456789 GTBank`\n\nType *cancel* to stop."
            );

            return StepResult::stay(['action' => 'sell_prompt_amount']);
        }

        $amountStr = $parsed['amount'];
        $asset = $parsed['asset'];
        $fiat = strtoupper((string) $ctx->stateValue('fiat', 'NGN'));

        WalletChoice::prompt($ctx, "Cash out *{$amountStr} {$asset}* from which wallet?");

        return StepResult::transition('sell', 'wallet', [
            'fiat' => $fiat,
            'crypto_amount' => $amountStr,
            'asset' => $asset,
            'xof_country' => $ctx->stateValue('xof_country'),
        ], ['action' => 'sell_wallet_prompt']);
    }
}
