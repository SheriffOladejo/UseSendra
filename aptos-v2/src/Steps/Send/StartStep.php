<?php

declare(strict_types=1);

namespace AptosV2\Steps\Send;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\StellarAddress;
use AptosV2\Support\WalletChoice;

/**
 * Entry point for crypto P2P send (`send 50 USDT to …`).
 *
 * Naira bank cash-out (`send 5000 naira to …`) is excluded from intents and
 * stays on the legacy sell flow.
 */
final class StartStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if (IntentParser::isHelpOnly($ctx->messageLower)) {
            $this->reply(
                $ctx,
                "Please provide the full details to send:\n\n" .
                "Format: *send [amount] [currency] to [recipient]*\n\n" .
                "Examples:\n" .
                "`send 50 USDT to 2348012345678`\n" .
                "`send 10 USDC to 0x1234...`\n\n" .
                "Recipient can be a *phone number*, *Aptos address*, or *Stellar address*.\n" .
                'Supported currencies: *USDT*, *USDC*'
            );

            return StepResult::complete(['action' => 'send_help']);
        }

        $parsed = IntentParser::parse($ctx->message);
        if ($parsed === null) {
            $parts = preg_split('/\s+/', trim($ctx->message));
            if (count($parts) >= 3) {
                $amount = $parts[1];
                $currency = strtoupper($parts[2]);
                if (!is_numeric($amount) || (float) $amount <= 0) {
                    $this->reply($ctx, '⚠️ Please specify a valid amount greater than 0.');

                    return StepResult::complete(['action' => 'send_invalid_amount']);
                }
                if (!in_array($currency, ['USDT', 'USDC'], true)) {
                    $this->reply($ctx, "⚠️ Unsupported currency *{$currency}*. Use USDT or USDC.");

                    return StepResult::complete(['action' => 'send_invalid_currency']);
                }
            }

            $this->reply(
                $ctx,
                "⚠️ Format: *send [amount] [currency] to [address]*\n" .
                'Example: `send 50 USDT to 0x00...` or `send 10 USDC to G… / C…`'
            );

            return StepResult::complete(['action' => 'send_format_error']);
        }

        $recipient = $parsed['recipient'];
        // WhatsApp often wraps long addresses with hyphens/spaces — normalize before classifying.
        if (StellarAddress::isValid($recipient)) {
            $parsed['recipient'] = StellarAddress::normalize($recipient);
            $parsed['chain'] = 'stellar';
            /** @var ExecutionService $executor */
            $executor = $ctx->services->get('send_execution');

            return $executor->initiate($ctx, $parsed);
        }
        if (preg_match('/^0x[a-fA-F0-9]{60,64}$/', $recipient)) {
            $parsed['chain'] = 'aptos';
            /** @var ExecutionService $executor */
            $executor = $ctx->services->get('send_execution');

            return $executor->initiate($ctx, $parsed);
        }

        // Wallet pick for phone / username — do NOT recommend Stellar on send (buy-only).
        WalletChoice::prompt(
            $ctx,
            "Send *{$parsed['amount']} {$parsed['currency']}* from which wallet?\n\nRecipient: `{$recipient}`",
            [],
            false
        );

        return StepResult::transition('send', 'wallet', [
            'amount' => $parsed['amount'],
            'currency' => $parsed['currency'],
            'recipient' => $recipient,
        ], ['action' => 'send_wallet_prompt']);
    }
}
