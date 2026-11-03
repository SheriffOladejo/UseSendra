<?php

declare(strict_types=1);

namespace AptosV2\Steps\Polymarket;

use App\Services\SendraDayaPolymarketService;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

final class Guard
{
    public static function entryBlock(StepContext $ctx): ?StepResult
    {
        if (empty($ctx->user['aptos_address'])) {
            $ctx->whatsapp()->sendMessage($ctx->from, '❌ No wallet on file. Type *hi* to get started.');

            return StepResult::complete(['action' => 'polymarket_blocked']);
        }
        if (!empty($ctx->user['pending_tx_id'])) {
            $ctx->whatsapp()->sendMessage(
                $ctx->from,
                '⚠️ You already have a pending transaction. Tap *Enter PIN* on our last message, or type *cancel*.'
            );

            return StepResult::complete(['action' => 'polymarket_pending_exists']);
        }

        $svc = new SendraDayaPolymarketService();
        if (!$svc->isConfigured()) {
            $ctx->whatsapp()->sendMessage(
                $ctx->from,
                "❌ Polymarket funding isn't available right now. Try again later or email support@usesendra.com"
            );

            return StepResult::complete(['action' => 'polymarket_unconfigured']);
        }

        $gate = $svc->assertOnchainPayout();
        if (!$gate['ok']) {
            $ctx->whatsapp()->sendMessage($ctx->from, (string) $gate['user_message']);

            return StepResult::complete(['action' => 'polymarket_chain_blocked']);
        }

        return null;
    }
}
