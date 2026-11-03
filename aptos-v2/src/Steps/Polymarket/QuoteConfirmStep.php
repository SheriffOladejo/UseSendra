<?php

declare(strict_types=1);

namespace AptosV2\Steps\Polymarket;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Polymarket\OrderService;
use AptosV2\Steps\Kyc\Gate as KycGate;

final class QuoteConfirmStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        if ($ctx->messageLower === 'polymarket_change') {
            $this->reply($ctx, 'How much Naira or USDT? (e.g. *5000* or *10 usdt*)');

            return StepResult::transition('polymarket', 'await_amount', [
                'polygon_address' => $ctx->stateValue('polygon_address'),
            ], ['action' => 'polymarket_change_amount']);
        }

        if (!IntentParser::messageMeansYes($ctx->message, $ctx->messageLower)) {
            $this->reply($ctx, "Reply *yes* to get bank details, or tap *Different amount*.\n*cancel* stops.");

            return StepResult::stay(['action' => 'polymarket_quote_reprompt']);
        }

        $payNgn = (float) $ctx->stateValue('pay_ngn', 0);
        if ($kycBlock = KycGate::blockIfNeeded($ctx, $payNgn, $ctx->state)) {
            return $kycBlock;
        }

        $this->reply($ctx, '⏳ Opening your payment account…');

        /** @var OrderService $orders */
        $orders = $ctx->services->get('polymarket_order');

        return ResultMapper::fromService($orders->createAndSendBank($ctx->from, $ctx->user, $ctx->state));
    }
}
