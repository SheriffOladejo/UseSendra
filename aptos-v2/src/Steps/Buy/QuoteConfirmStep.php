<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Buy\OrderService;
use AptosV2\Steps\Kyc\Gate as KycGate;

/**
 * Confirm quoted buy rate (`await_quote_confirm`).
 */
final class QuoteConfirmStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }
        if ($block = Guard::centiivConfiguredOrExit($ctx, $ctx->whatsapp())) {
            return $block;
        }

        if (IntentParser::messageMeansYes($ctx->message, $ctx->messageLower)) {
            $payNgn = (float) ($ctx->stateValue('pay_ngn', 0));
            if ($payNgn <= 0) {
                $payNgn = (float) ($ctx->stateValue('quoted_pay_ngn', 0));
            }
            if ($kycBlock = KycGate::blockIfNeeded($ctx, $payNgn, $ctx->state)) {
                return $kycBlock;
            }

            $this->reply($ctx, '⏳ Opening your payment account…');

            /** @var OrderService $orders */
            $orders = $ctx->services->get('buy_order');
            $result = $orders->createOrderAndSendBankDetails($ctx->from, $ctx->user, $ctx->state, false);

            if ($result['outcome'] === 'stay' && ($result['meta']['action'] ?? '') === 'buy_opening_processing') {
                return StepResult::transition('buy', 'await_opening_order', [
                    'opening_started_at' => time(),
                ], $result['meta']);
            }

            return ResultMapper::fromService($result);
        }

        if ($ctx->messageLower === 'buy_quote_change') {
            $this->reply($ctx, 'How much Naira do you want to spend? (e.g. *5000*)');

            return StepResult::transition('buy', 'await_amount', ['fiat' => 'NGN'], ['action' => 'buy_change_amount']);
        }

        $this->reply($ctx, "Reply *yes* to get bank details, or tap *Different amount*.\n*cancel* stops.");

        return StepResult::stay(['action' => 'buy_quote_reprompt']);
    }
}
