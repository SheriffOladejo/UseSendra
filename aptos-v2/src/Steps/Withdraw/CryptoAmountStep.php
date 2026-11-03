<?php

declare(strict_types=1);

namespace AptosV2\Steps\Withdraw;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Sell\Guard;
use AptosV2\Support\AptosBalanceService;

/**
 * Collect crypto amount after asset pick, then hand off to sell bank step.
 */
final class CryptoAmountStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $asset = (string) $ctx->stateValue('asset', '');
        if ($asset === '') {
            return StepResult::transition('withdraw', 'asset', [], ['action' => 'withdraw_asset_missing']);
        }

        $amountStr = $this->parseAmount($ctx->message);
        if ($amountStr === null) {
            $this->reply(
                $ctx,
                "How much *{$asset}* do you want to withdraw?\nExample: *5* or *10.5*\n\n*cancel* stops."
            );

            return StepResult::stay(['action' => 'withdraw_crypto_amount_reprompt']);
        }

        /** @var AptosBalanceService $balances */
        $balances = $ctx->services->get('aptos_balance');
        if ($fail = Guard::validateCryptoAmount($ctx, $ctx->whatsapp(), $balances, $amountStr, $asset)) {
            return $fail;
        }

        if ($missing = Guard::completeIfOneclickMissing($ctx, $ctx->whatsapp())) {
            return $missing;
        }

        $this->reply(
            $ctx,
            "🏦 *Bank payout*\nCashing out *{$amountStr} {$asset}*.\n\n" .
            "Reply in this format:\n`0123456789 BankName`\nExample: `0123456789 GTBank`\n\n" .
            'Type *cancel* to stop.'
        );

        return StepResult::transition('sell', 'bank', [
            'fiat' => (string) $ctx->stateValue('fiat', 'NGN'),
            'crypto_amount' => $amountStr,
            'asset' => $asset,
            'from_withdraw' => true,
        ], ['action' => 'withdraw_bank_prompt']);
    }

    private function parseAmount(string $message): ?string
    {
        $t = trim($message);
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(?:usdc|usdt)?\s*$/iu', $t, $m)) {
            return null;
        }

        $amount = (float) $m[1];

        return $amount >= 0.01 ? (string) $m[1] : null;
    }
}
