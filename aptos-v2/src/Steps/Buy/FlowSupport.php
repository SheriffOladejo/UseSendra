<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Buy\QuoteService;
use AptosV2\Steps\Commands\MenuContent;

/**
 * Shared buy-flow exits (cancel, menu).
 */
final class FlowSupport
{
    public static function cancelExit(StepContext $ctx): StepResult
    {
        /** @var QuoteService $quotes */
        $quotes = $ctx->services->get('buy_quote');
        $quotes->cancelActiveForPhone($ctx->from);
        $ctx->whatsapp()->sendMessage($ctx->from, "Cancelled. Type *menu* for options.");

        return StepResult::complete(['action' => 'buy_cancelled']);
    }

    public static function menuExit(StepContext $ctx): StepResult
    {
        /** @var QuoteService $quotes */
        $quotes = $ctx->services->get('buy_quote');
        $quotes->cancelActiveForPhone($ctx->from);

        $name = trim((string) ($ctx->displayName ?: 'there'));
        $ctx->whatsapp()->sendButtonMessage(
            $ctx->from,
            MenuContent::body($name),
            MenuContent::buttons()
        );

        return StepResult::complete(['action' => 'buy_menu']);
    }

    public static function earlyExit(StepContext $ctx): ?StepResult
    {
        if ($ctx->messageLower === 'menu' || $ctx->messageLower === '/menu') {
            return self::menuExit($ctx);
        }
        if (in_array($ctx->messageLower, ['cancel', '/cancel', 'stop'], true)) {
            return self::cancelExit($ctx);
        }

        return null;
    }
}
