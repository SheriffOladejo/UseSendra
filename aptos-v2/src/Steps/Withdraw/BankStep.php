<?php

declare(strict_types=1);

namespace AptosV2\Steps\Withdraw;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Sell\Guard;

/**
 * Collect bank account for fixed-Naira withdraw, then pick USDT/USDC balance.
 */
final class BankStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        if ($missing = Guard::completeIfOneclickMissing($ctx, $ctx->whatsapp())) {
            return $missing;
        }

        if (!preg_match('/^(\d{10})\s+(.+)$/u', trim($ctx->message), $m)) {
            $this->reply(
                $ctx,
                "Use: `0123456789 BankName` (10-digit NGN account).\nType *cancel* to stop."
            );

            return StepResult::stay(['action' => 'withdraw_bank_format']);
        }

        $targetNgn = (float) $ctx->stateValue('target_receive_ngn', 0);
        if ($targetNgn < 100) {
            return StepResult::transition('withdraw', 'await_naira_amount', [], ['action' => 'withdraw_naira_amount_missing']);
        }

        $account = $m[1];
        $bank = trim($m[2]);
        $formatted = number_format($targetNgn, 0, '.', ',');

        $this->buttons(
            $ctx,
            "Send *≈ ₦{$formatted}* to `{$account}` (*{$bank}*).\n\nWhich balance should we use?",
            [
                'sell_pick_usdt' => 'USDT',
                'sell_pick_usdc' => 'USDC',
            ]
        );

        return StepResult::transition('sell', 'asset', [
            'fiat' => (string) $ctx->stateValue('fiat', 'NGN'),
            'target_receive_ngn' => $targetNgn,
            'preset_account' => $account,
            'preset_bank_guess' => $bank,
            'from_withdraw' => true,
        ], ['action' => 'withdraw_send_naira_pick']);
    }
}
