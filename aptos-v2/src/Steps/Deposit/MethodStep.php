<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Commands\CommandSupport;

/**
 * Pick deposit method — on-chain receive vs Naira bank transfer.
 */
final class MethodStep extends AbstractStep
{
    use CommandSupport;

    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        if ($ctx->messageLower === 'deposit_receive_crypto') {
            return $this->showReceiveAddress($ctx);
        }

        if ($ctx->messageLower === 'deposit_solana_crosschain') {
            $this->buttons(
                $ctx,
                'Which asset do you want to deposit from Solana?',
                [
                    'deposit_sol_usdc' => 'USDC (Solana)',
                    'deposit_sol_usdt' => 'USDT (Solana)',
                ]
            );

            return StepResult::transition('deposit', 'solana_asset', [], ['action' => 'deposit_solana_asset_prompt']);
        }

        if ($ctx->messageLower === 'deposit_bank_transfer') {
            $this->reply(
                $ctx,
                "How much Naira do you want to deposit?\nExample: *5000* or *10000 naira*\n\n*cancel* stops."
            );

            return StepResult::transition('deposit', 'await_amount', ['fiat' => 'NGN'], ['action' => 'deposit_await_amount']);
        }

        $this->buttons(
            $ctx,
            'How would you like to fund your wallet?',
            [
                'deposit_receive_crypto'    => 'Aptos Wallet Address',
                'deposit_solana_crosschain' => 'Deposit Solana (USDT/USDC)',
                'deposit_bank_transfer'     => 'Bank Transfer (NGN)',
            ]
        );

        return StepResult::stay(['action' => 'deposit_method_reprompt']);
    }

    private function showReceiveAddress(StepContext $ctx): StepResult
    {
        $wallet = $this->walletAddress($ctx);
        if ($wallet === null) {
            $this->reply($ctx, "❌ No wallet found. Type *hi* to create one.");

            return StepResult::complete(['action' => 'deposit_receive', 'error' => 'no_wallet']);
        }

        $this->reply($ctx, '💳 *Your Deposit Address*');
        usleep(300000);
        $this->reply($ctx, $wallet);
        usleep(300000);
        $this->reply(
            $ctx,
            "*📋 Instructions:*\n" .
            "• Copy the address above\n" .
            "• Share it with your sender\n" .
            "• Ask them to send on the *Aptos network* only\n\n" .
            "*⚠️ Important:*\n" .
            "• Only USDC or USDT on Aptos is supported\n" .
            "• Wrong network = lost funds\n" .
            "• You'll be notified here when funds arrive"
        );

        return StepResult::complete(['action' => 'deposit_receive']);
    }
}
