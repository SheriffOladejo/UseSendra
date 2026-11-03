<?php

declare(strict_types=1);

namespace AptosV2\Steps\Send;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\StellarWalletService;
use AptosV2\Support\WalletChoice;

/**
 * After a P2P send is parsed, pick Aptos vs Stellar.
 */
final class WalletStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($this->wantsCancel($ctx)) {
            $this->reply($ctx, 'Cancelled. Type *menu* for options.');

            return StepResult::complete(['action' => 'send_cancelled']);
        }

        $chain = WalletChoice::parse($ctx->messageLower);
        if ($chain === WalletChoice::SOLANA || $chain === WalletChoice::POLYGON) {
            $chain = null;
        }
        if ($chain === null) {
            WalletChoice::prompt(
                $ctx,
                "Send *{$ctx->stateValue('amount')} {$ctx->stateValue('currency')}* from which wallet?"
            );

            return StepResult::stay(['action' => 'send_wallet_reprompt']);
        }

        /** @var ExecutionService $executor */
        $executor = $ctx->services->get('send_execution');

        return $executor->initiate($ctx, [
            'amount' => (string) $ctx->stateValue('amount', ''),
            'currency' => (string) $ctx->stateValue('currency', ''),
            'recipient' => (string) $ctx->stateValue('recipient', ''),
            'chain' => $chain,
        ]);
    }
}
