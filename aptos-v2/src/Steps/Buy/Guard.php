<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use App\Services\SendraBuyRampService;
use App\Services\WhatsAppService;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

/**
 * Shared buy entry guards.
 */
final class Guard
{
    public static function entryBlock(StepContext $ctx, WhatsAppService $whatsapp): ?StepResult
    {
        if (empty($ctx->user['aptos_address'])) {
            $whatsapp->sendMessage($ctx->from, '❌ No wallet on file. Type *hi* to get started.');

            return StepResult::complete(['action' => 'buy_blocked']);
        }

        if (!empty($ctx->user['pending_tx_id'])) {
            $whatsapp->sendMessage(
                $ctx->from,
                '⚠️ You already have a pending transaction. Tap *Enter PIN* on our last message, or type *cancel*.'
            );

            return StepResult::complete(['action' => 'buy_pending_exists']);
        }

        $buySvc = new SendraBuyRampService();
        $centiivOk = $buySvc->isCentiivConfigured();
        $dayaOk = \App\Services\SendraAptosRamp::usesDayaForAptos();
        if (!$centiivOk && !$dayaOk) {
            $whatsapp->sendMessage(
                $ctx->from,
                "❌ Buy isn't available right now. Try again later or email support@usesendra.com"
            );

            return StepResult::complete(['action' => 'buy_unconfigured']);
        }

        return null;
    }

    public static function providerConfiguredOrExit(StepContext $ctx, WhatsAppService $whatsapp): ?StepResult
    {
        $dest = strtolower((string) $ctx->stateValue('dest_chain', 'aptos'));
        if ($dest === 'aptos' && \App\Services\SendraAptosRamp::usesDayaForAptos()) {
            return null;
        }
        $buySvc = new SendraBuyRampService();
        if (!$buySvc->isCentiivConfigured()) {
            $whatsapp->sendMessage($ctx->from, '❌ Buy is temporarily unavailable. Try again later.');

            return StepResult::complete(['action' => 'buy_unconfigured']);
        }

        return null;
    }

    /** @deprecated use providerConfiguredOrExit */
    public static function centiivConfiguredOrExit(StepContext $ctx, WhatsAppService $whatsapp): ?StepResult
    {
        return self::providerConfiguredOrExit($ctx, $whatsapp);
    }
}
