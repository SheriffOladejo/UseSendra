<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use App\Services\SendraOneClickService;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Commands\CommandSupport;

/**
 * Handles 1Click swap deposit amount for Sendra Wallet destination.
 * Generates temporary deposit address on chosen network (Solana/Stellar/Base/Arbitrum).
 */
final class OneClickAmountStep extends AbstractStep
{
    use CommandSupport;

    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $network = (string) ($ctx->state['deposit_network'] ?? 'Solana');
        $asset = (string) ($ctx->state['deposit_asset'] ?? 'USDC');
        $rawMsg = trim($ctx->message);

        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $rawMsg, $m) !== 1) {
            $this->reply(
                $ctx,
                "Please enter a valid numeric amount (e.g. *50* or *300*).\n\n" .
                "*cancel* stops."
            );

            return StepResult::stay(['action' => 'deposit_1click_amount_invalid']);
        }

        $amount = (float) $m[1];
        if ($amount < 1.0) {
            $this->reply($ctx, "❌ Minimum deposit amount is *1 {$asset}*. Please enter a larger amount.");

            return StepResult::stay(['action' => 'deposit_1click_amount_below_min']);
        }

        $wallet = $this->walletAddress($ctx);
        if ($wallet === null) {
            $this->reply($ctx, "❌ No wallet found. Type *hi* to create one.");

            return StepResult::complete(['action' => 'deposit_1click', 'error' => 'no_wallet']);
        }

        $this->reply($ctx, "⏳ Requesting temporary deposit address on *{$network}*...");

        $oneClick = new SendraOneClickService();
        $destAsset = (string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near');
        if ($asset === 'USDT' && $network === 'Solana') {
            $destAsset = (string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near');
        }

        $smallestUnits = SendraOneClickService::cryptoAmountToSmallestUnits((string) $amount, 6);

        $quoteBody = SendraOneClickService::buildCrossChainToAptosQuoteBody(
            $network,
            $asset,
            $destAsset,
            $smallestUnits,
            $wallet,
            null,
            gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60),
            false
        );

        $quoteRes = $oneClick->requestQuote($quoteBody);
        if (!$quoteRes['success'] || !is_array($quoteRes['data'] ?? null)) {
            $errMsg = $quoteRes['error'] ?? 'Quote service unavailable';
            $this->reply($ctx, "❌ Could not generate temporary deposit address: {$errMsg}\n\nPlease try again in a few moments.");

            return StepResult::complete(['action' => 'deposit_1click_quote_failed', 'error' => $errMsg]);
        }

        $parsed = SendraOneClickService::parseQuoteResponse($quoteRes['data']);
        $depositAddress = $parsed['deposit_address'] ?? null;
        $depositMemo = $parsed['deposit_memo'] ?? null;

        if ($depositAddress === null || $depositAddress === '') {
            $this->reply($ctx, "❌ Could not retrieve temporary deposit address. Please try again.");

            return StepResult::complete(['action' => 'deposit_1click_no_address']);
        }

        $estOutFormatted = $parsed['amount_out_formatted'] ?? (string) $amount;
        $correlationId = $parsed['correlation_id'] ?? '';

        // Save pending transaction
        $meta = [
            'network' => $network,
            'deposit_address' => $depositAddress,
            'deposit_memo' => $depositMemo,
            'correlation_id' => $correlationId,
            'origin_asset' => $asset,
            'target_asset' => $asset,
            'planned_amount' => $amount,
            'aptos_v2' => true,
            'origin_whatsapp_phone_id' => $ctx->phoneId,
        ];

        try {
            $stmt = $ctx->db->prepare("
                INSERT INTO pending_transactions 
                (user_id, tx_type, amount, currency, status, created_at, human_description)
                VALUES (?, 'deposit_1click_crosschain', ?, ?, 'pending', NOW(), ?)
            ");
            $stmt->execute([
                $ctx->user['Id'] ?? $ctx->user['id'],
                $amount,
                $asset,
                json_encode($meta),
            ]);
        } catch (\Throwable $e) {
            error_log('Failed to save deposit_1click_crosschain pending row: ' . $e->getMessage());
        }

        // Summary message
        $summaryText =
            "📥 *Deposit {$asset} on {$network}*\n\n" .
            "Send *{$amount} {$asset}* on *{$network}* to the temporary deposit address below.\n\n" .
            "📊 *Estimated Output:* ~{$estOutFormatted} {$asset} on Aptos\n" .
            "⏱️ *Deposit Window:* 45 minutes";

        $this->reply($ctx, $summaryText);

        // Standalone Address Message for 1-Tap Copy
        $this->reply($ctx, $depositAddress);

        // Standalone Memo Message for 1-Tap Copy (if memo exists)
        if ($depositMemo !== null && $depositMemo !== '') {
            $this->reply($ctx, "📌 *Memo / Destination Tag:*");
            $this->reply($ctx, $depositMemo);
        }

        // Instructions message
        $instructionsText =
            "⚠️ *Important:* Send only *{$asset}* on *{$network}*, and send the *exact amount* (*{$amount} {$asset}*) to the address above.\n" .
            "A different token or amount may not credit.\n\n" .
            "📋 *Instructions:*\n" .
            "• Copy the address above\n" .
            "• Send on the *{$network} Network* only\n" .
            "• Funds will automatically credit to your Aptos wallet once detected";

        $this->reply($ctx, $instructionsText);

        return StepResult::complete(['action' => 'deposit_1click_generated', 'address' => $depositAddress]);
    }
}
