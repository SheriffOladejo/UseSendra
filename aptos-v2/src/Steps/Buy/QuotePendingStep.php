<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Waiting for async quote worker (`quote_pending`).
 */
final class QuotePendingStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        if (IntentParser::isNewBuyCommand($ctx->messageLower)) {
            $this->reply(
                $ctx,
                "⏳ A rate is still loading.\n\nType *cancel* first, then start your buy again."
            );

            return StepResult::stay(['action' => 'buy_quote_pending_cancel_first']);
        }

        if (in_array($ctx->messageLower, ['buy_pick_usdt', 'buy_pick_usdc', 'usdt', 'usdc'], true)) {
            return StepResult::stay(['action' => 'buy_quote_pending_ignore_asset']);
        }

        if (empty($ctx->state['quote_nudge_sent'])) {
            $this->reply($ctx, "⏳ Still getting your rate…\n\n*cancel* stops this request.");

            return StepResult::transition('buy', 'quote_pending', [
                'quote_nudge_sent' => true,
            ], ['action' => 'buy_quote_pending']);
        }

        $this->reply($ctx, "⏳ Still getting your rate…\n\n*cancel* stops this request.");

        return StepResult::stay(['action' => 'buy_quote_pending']);
    }
}
