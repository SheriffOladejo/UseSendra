<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use App\Services\SendraDayaPolymarketService;
use AptosV2\Services\Buy\QuoteService;
use AptosV2\Support\WalletChoice;

/**
 * Pick USDT/USDC after Naira amount (`await_asset`).
 */
final class AssetStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }
        if ($block = Guard::centiivConfiguredOrExit($ctx, $ctx->whatsapp())) {
            return $block;
        }

        $asset = IntentParser::parseAssetPick($ctx->messageLower);
        if ($asset === null) {
            $this->reply($ctx, "Tap *USDT* or *USDC*, or type the name.\n*cancel* stops.");

            return StepResult::stay(['action' => 'buy_asset_reprompt']);
        }

        WalletChoice::prompt(
            $ctx,
            "You'll buy *{$asset}*. Which wallet should receive it?",
            [],
            strtoupper($asset) === 'USDC' || strtoupper($asset) === 'USDT',
            true,
            (new SendraDayaPolymarketService())->isConfigured()
        );

        return StepResult::transition('buy', 'await_wallet', [
            'fiat' => 'NGN',
            'pay_ngn' => (float) ($ctx->state['pay_ngn'] ?? $ctx->stateValue('pay_ngn', 0)),
            'asset' => $asset,
            'quote_mode' => 'naira',
        ], ['action' => 'buy_wallet_prompt']);
    }
}
