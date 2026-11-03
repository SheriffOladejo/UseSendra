<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use App\Services\SendraCentiivRampService;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Buy\QuoteService;

/**
 * Collect an external Solana destination for Centiiv on-ramp (no Sendra Solana wallet).
 */
final class SolanaAddressStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $addr = SendraCentiivRampService::normalizeSolanaAddressInput($ctx->message);
        if (!SendraCentiivRampService::looksLikeSolanaAddress($addr)) {
            $this->reply(
                $ctx,
                "That doesn't look like a Solana address.\n\n"
                . "Paste the Solana address that should receive USDC after you pay Naira.\n\n"
                . '*cancel* stops.'
            );

            return StepResult::stay(['action' => 'buy_solana_address_reprompt']);
        }

        /** @var QuoteService $quotes */
        $quotes = $ctx->services->get('buy_quote');
        $quoteMode = (string) $ctx->stateValue('quote_mode', 'naira');

        return ResultMapper::fromService($quotes->enqueueAndPrompt(
            $ctx->from,
            $ctx->user,
            $quoteMode,
            (string) $ctx->stateValue('asset', 'USDC'),
            isset($ctx->state['pay_ngn']) ? (float) $ctx->state['pay_ngn'] : null,
            isset($ctx->state['target_crypto']) ? (float) $ctx->state['target_crypto'] : null,
            $ctx->inboundWamid,
            'solana',
            $addr
        ));
    }
}
