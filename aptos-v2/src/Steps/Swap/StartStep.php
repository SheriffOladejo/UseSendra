<?php

declare(strict_types=1);

namespace AptosV2\Steps\Swap;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

final class StartStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if (!self::swapEnabled()) {
            $this->reply(
                $ctx,
                "❌ Token swaps aren't available right now.\n\nAsk support to enable *SENDRA_PANORA_WALLET_SWAP*."
            );

            return StepResult::complete(['action' => 'swap_disabled']);
        }

        if (!empty($ctx->user['pending_tx_id'])) {
            $this->reply(
                $ctx,
                "⚠️ You already have a pending transaction. Tap *Enter PIN* on our last message, or type *cancel*."
            );

            return StepResult::complete(['action' => 'swap_pending_exists']);
        }

        $parsed = IntentParser::parse($ctx->message);
        if ($parsed === null) {
            $this->reply(
                $ctx,
                "🔄 *Swap USDT ↔ USDC* (in your Sendra wallet)\n\n" .
                "Examples:\n" .
                "`swap 2 USDT to USDC`\n" .
                "`swap 5 USDC to USDT`\n\n" .
                "Minimum *0.01*."
            );

            return StepResult::complete(['action' => 'swap_help']);
        }

        $amountStr = rtrim(rtrim(number_format((float) $parsed['amount'], 6, '.', ''), '0'), '.');
        if ($amountStr === '') {
            $amountStr = (string) $parsed['amount'];
        }

        $this->buttons(
            $ctx,
            "🔄 *Confirm swap*\n\n" .
            "Swap *{$amountStr} {$parsed['from_asset']}* → *{$parsed['to_asset']}*?\n\n" .
            "Tap *Confirm* to get a live quote and proceed.",
            [
                'swap_confirm' => '✅ Confirm',
                'cancel'       => '✖️ Cancel',
            ]
        );

        return StepResult::transition('swap', 'confirm', [
            'amount' => $parsed['amount'],
            'amount_str' => $amountStr,
            'from_asset' => $parsed['from_asset'],
            'to_asset' => $parsed['to_asset'],
            'direction' => $parsed['direction'],
        ], ['action' => 'swap_confirm_prompt']);
    }

    private static function swapEnabled(): bool
    {
        $raw = $_ENV['SENDRA_PANORA_WALLET_SWAP'] ?? getenv('SENDRA_PANORA_WALLET_SWAP');
        $v = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}
