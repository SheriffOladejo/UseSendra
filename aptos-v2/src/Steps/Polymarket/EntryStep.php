<?php

declare(strict_types=1);

namespace AptosV2\Steps\Polymarket;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

final class EntryStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($block = Guard::entryBlock($ctx)) {
            return $block;
        }

        $parsed = IntentParser::parseEntry($ctx->message, $ctx->messageLower);
        if ($parsed === null) {
            $this->reply(
                $ctx,
                "Fund Polymarket with Naira → USDT on Polygon.\n\n" .
                "• *polymarket*\n" .
                "• *polymarket 5000* (spend Naira)\n" .
                "• *polymarket 10 usdt*\n\n" .
                '*cancel* stops.'
            );

            return StepResult::complete(['action' => 'polymarket_usage']);
        }

        $patch = ['fiat' => 'NGN'];
        if (($parsed['type'] ?? '') === 'naira') {
            $patch['quote_mode'] = 'naira';
            $patch['pay_ngn'] = $parsed['pay_ngn'];
        } elseif (($parsed['type'] ?? '') === 'crypto') {
            $patch['quote_mode'] = 'crypto_target';
            $patch['target_crypto'] = $parsed['target_crypto'];
        }

        $this->reply(
            $ctx,
            "Paste the *Polygon deposit address Polymarket showed you* (starts with `0x`).\n\n" .
            "We send *USDT* on Polygon to that address. Sendra does not have a Polygon wallet — a wrong address cannot be reversed.\n\n" .
            '*cancel* stops.'
        );

        return StepResult::transition('polymarket', 'await_address', $patch, ['action' => 'polymarket_ask_address']);
    }
}
