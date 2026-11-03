<?php

declare(strict_types=1);

namespace AptosV2\Steps\Deposit;

use App\Services\SendraOneClickService;
use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Steps\Commands\CommandSupport;
use PDO;

/**
 * Handles amount input for Solana cross-chain deposit, generates 1Click temporary deposit address,
 * records pending transaction, and replies with deposit instructions.
 */
final class SolanaAmountStep extends AbstractStep
{
    use CommandSupport;

    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        $asset = (string) ($ctx->state['solana_asset'] ?? 'USDC');
        $rawMsg = trim($ctx->message);
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $rawMsg, $m) !== 1) {
            $this->reply(
                $ctx,
                "Please enter a valid numeric amount to deposit (e.g. *20* or *50*).\n\n" .
                "*cancel* stops."
            );

            return StepResult::stay(['action' => 'deposit_solana_amount_invalid']);
        }

        $amount = (float) $m[1];
        if ($amount < 1.0) {
            $this->reply($ctx, "❌ Minimum deposit amount is *1 {$asset}*. Please enter a larger amount.");

            return StepResult::stay(['action' => 'deposit_solana_amount_below_min']);
        }

        $wallet = $this->walletAddress($ctx);
        if ($wallet === null) {
            $this->reply($ctx, "❌ No wallet found. Type *hi* to create one.");

            return StepResult::complete(['action' => 'deposit_solana', 'error' => 'no_wallet']);
        }

        $this->reply($ctx, "⏳ Generating temporary deposit address...");

        $oneClick = new SendraOneClickService();
        $originAsset = $asset === 'USDT'
            ? 'nep141:solana-usdt.omft.near'
            : 'nep141:solana-usdc.omft.near';

        $destAsset = $asset === 'USDT'
            ? (string) ($_ENV['SENDRA_ONECLICK_APTOS_USDT_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDT_ASSET_ID') ?: 'nep141:aptos-88cb7619440a914fe6400149a12b443c3ac21d59.omft.near')
            : (string) ($_ENV['SENDRA_ONECLICK_APTOS_USDC_ASSET_ID'] ?? getenv('SENDRA_ONECLICK_APTOS_USDC_ASSET_ID') ?: 'nep141:aptos-34ee497f210c5a511e8d5b53bc56d75b63612bb5.omft.near');

        $smallestUnits = SendraOneClickService::cryptoAmountToSmallestUnits((string) $amount, 6);
        $refundAddress = (string) ($_ENV['SENDRA_SOLANA_REFUND_ADDRESS'] ?? getenv('SENDRA_SOLANA_REFUND_ADDRESS') ?: 'Dr2PgL5XTSjBsG8RjJE82dceaMKdxZFica3CrXbT3o8z');

        $quoteBody = SendraOneClickService::buildSolanaToAptosQuoteBody(
            $originAsset,
            $destAsset,
            $smallestUnits,
            $wallet,
            $refundAddress,
            gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 60),
            false
        );

        $quoteRes = $oneClick->requestQuote($quoteBody);
        if (!$quoteRes['success'] || !is_array($quoteRes['data'] ?? null)) {
            $errMsg = $quoteRes['error'] ?? 'Quote service unavailable';
            $this->reply($ctx, "❌ Could not generate temporary deposit address right now: {$errMsg}\n\nPlease try again in a few moments.");

            return StepResult::complete(['action' => 'deposit_solana_quote_failed', 'error' => $errMsg]);
        }

        $parsed = SendraOneClickService::parseQuoteResponse($quoteRes['data']);
        $depositAddress = $parsed['deposit_address'] ?? null;
        if ($depositAddress === null || $depositAddress === '') {
            $this->reply($ctx, "❌ Could not retrieve deposit address from 1Click. Please try again.");

            return StepResult::complete(['action' => 'deposit_solana_no_address']);
        }

        $estOutFormatted = $parsed['amount_out_formatted'] ?? (string) $amount;
        $correlationId = $parsed['correlation_id'] ?? '';

        // Save pending transaction
        $meta = [
            'deposit_address' => $depositAddress,
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
                VALUES (?, 'deposit_1click_solana', ?, ?, 'pending', NOW(), ?)
            ");
            $stmt->execute([
                $ctx->user['Id'] ?? $ctx->user['id'],
                $amount,
                $asset,
                json_encode($meta),
            ]);
        } catch (\Throwable $e) {
            error_log('Failed to save deposit_1click_solana pending row: ' . $e->getMessage());
        }

        $shortRefund = strlen($refundAddress) > 12
            ? substr($refundAddress, 0, 6) . '...' . substr($refundAddress, -4)
            : $refundAddress;

        // Summary message
        $summaryText =
            "📥 *Deposit {$asset} on Solana*\n\n" .
            "Send *{$amount} {$asset}* on Solana to the temporary deposit address below.\n\n" .
            "📊 *Estimated Output:* ~{$estOutFormatted} {$asset} on Aptos\n" .
            "⏱️ *Deposit Window:* 45 minutes\n" .
            "🛡️ *Refund Fallback:* `{$shortRefund}`";

        $this->reply($ctx, $summaryText);

        // Standalone Address Message for 1-Tap Copy
        $this->reply($ctx, $depositAddress);

        // Instructions message
        $instructionsText =
            "⚠️ *Important:* Send only *{$asset}* on *Solana*, and send the *exact amount* (*{$amount} {$asset}*) to the address above.\n" .
            "A different token or amount may not credit.\n\n" .
            "📋 *Instructions:*\n" .
            "• Copy the address above\n" .
            "• Send on the *Solana Network* only\n" .
            "• Funds will automatically credit to your Aptos wallet once detected";

        $this->reply($ctx, $instructionsText);

        return StepResult::complete(['action' => 'deposit_solana_address_generated', 'address' => $depositAddress]);
    }
}
