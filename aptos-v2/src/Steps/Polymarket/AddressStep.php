<?php

declare(strict_types=1);

namespace AptosV2\Steps\Polymarket;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Polymarket\OrderService;

final class AddressStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $addr = IntentParser::normalizePolygonAddress($ctx->message);
        if ($addr === null) {
            $this->reply(
                $ctx,
                "That doesn't look like a Polygon address. Paste the `0x…` address from Polymarket (40 hex characters after 0x).\n\n*cancel* stops."
            );

            return StepResult::stay(['action' => 'polymarket_address_reprompt']);
        }

        $ctxState = array_merge($ctx->state, ['polygon_address' => $addr]);
        $hasAmount = ((float) ($ctxState['pay_ngn'] ?? 0) >= 100)
            || ((float) ($ctxState['target_crypto'] ?? 0) >= 0.01);
        if ($hasAmount) {
            $payNgn = (float) ($ctxState['pay_ngn'] ?? 0);
            if ($payNgn >= 100) {
                if ($kycBlock = \AptosV2\Steps\Kyc\Gate::blockIfNeeded($ctx, $payNgn, array_merge([
                    'flow' => 'polymarket',
                    'step' => 'await_address',
                ], $ctxState))) {
                    return $kycBlock;
                }
            }
            /** @var OrderService $orders */
            $orders = $ctx->services->get('polymarket_order');

            return ResultMapper::fromService($orders->quoteAndPrompt($ctx->from, $ctxState));
        }

        $this->reply(
            $ctx,
            "Address saved.\n\nHow much do you want to fund?\n• Naira: *5000*\n• USDT: *10 usdt*\n\n*cancel* stops."
        );

        return StepResult::transition('polymarket', 'await_amount', [
            'polygon_address' => $addr,
        ], ['action' => 'polymarket_ask_amount']);
    }
}
