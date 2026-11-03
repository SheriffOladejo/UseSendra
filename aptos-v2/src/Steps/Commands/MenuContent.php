<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

/**
 * Shared main-menu copy and button labels (greeting + menu command).
 */
final class MenuContent
{
    public static function body(string $name): string
    {
        return "Hi {$name} 🙋🏽‍♀️, I'm Sendra. I help you receive, buy, sell, and spend your USDT/USDC right here.\n\n" .
            "💰 Check balance — just type *balance*\n" .
            "📊 Live buy/sell rates — type *rate*\n" .
            "📉 Buy USDT/USDC with Naira — type `buy` or `buy 5000` (amount in naira)\n" .
            "🎯 Fund Polymarket (USDT on Polygon) — type `polymarket`\n" .
            "📤 Send NGN to a Nigerian bank account — type `sell 5 USDT` or `send 20,000 naira to 67XXX GTBank`\n" .
            "📤 Send USDT/USDC — type `send 50 USDT to 081xxx` (phone, Aptos, or Stellar address)\n" .
            "🔄 Swap USDT ↔️ USDC — type `swap 2 USDT to USDC`\n" .
            "📥 Receive USDC/USDT — type `receive`\n" .
            "🔐 Change transaction PIN — type `change pin`\n" .
            "❓ Help — type `/help`";
    }

    /**
     * @return array<string, string>
     */
    public static function buttons(): array
    {
        return [
            'deposit' => 'Deposit',
            'withdraw' => 'Withdraw',
        ];
    }
}
