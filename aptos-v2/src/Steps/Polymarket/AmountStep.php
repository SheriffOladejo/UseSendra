<?php

declare(strict_types=1);

namespace AptosV2\Steps\Polymarket;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Polymarket\OrderService;
use AptosV2\Steps\Kyc\Gate as KycGate;

final class AmountStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $payNgn = IntentParser::parseNairaAmount($ctx->message);
        $crypto = null;
        if ($payNgn === null) {
            $crypto = IntentParser::parseCryptoAmount($ctx->message);
        }

        $patch = ['polygon_address' => (string) $ctx->stateValue('polygon_address', '')];
        if ($payNgn !== null) {
            $patch['quote_mode'] = 'naira';
            $patch['pay_ngn'] = $payNgn;
        } elseif ($crypto !== null) {
            $patch['quote_mode'] = 'crypto_target';
            $patch['target_crypto'] = $crypto;
        } elseif ((float) $ctx->stateValue('pay_ngn', 0) >= 100 || (float) $ctx->stateValue('target_crypto', 0) >= 0.01) {
            $patch['quote_mode'] = (string) $ctx->stateValue('quote_mode', 'naira');
            $patch['pay_ngn'] = $ctx->stateValue('pay_ngn');
            $patch['target_crypto'] = $ctx->stateValue('target_crypto');
        } else {
            $this->reply($ctx, "How much? Example: *5000* or *10 usdt*\n\n*cancel* stops.");

            return StepResult::stay(['action' => 'polymarket_amount_reprompt']);
        }

        $payNgnForKyc = (float) ($patch['pay_ngn'] ?? 0);
        if ($payNgnForKyc >= 100) {
            if ($kycBlock = KycGate::blockIfNeeded($ctx, $payNgnForKyc, array_merge([
                'flow' => 'polymarket',
                'step' => 'await_amount',
            ], $patch))) {
                return $kycBlock;
            }
        }

        /** @var OrderService $orders */
        $orders = $ctx->services->get('polymarket_order');

        return ResultMapper::fromService($orders->quoteAndPrompt($ctx->from, array_merge($ctx->state, $patch)));
    }
}
