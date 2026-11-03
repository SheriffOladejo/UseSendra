<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

final class HelpStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        $this->reply(
            $ctx,
            "*Sendra help*\n\n" .
            "💰 *Balance* — `balance`\n" .
            "📊 *Live rates* — `rate`\n" .
            "🛒 *Buy crypto* — `buy` or `buy 5000`\n" .
            "🎯 *Fund Polymarket* — `polymarket` or `polymarket 10 usdt`\n" .
            "📈 *Sell crypto* — `sell` or `sell 20 USDC`\n" .
            "💸 *Send Naira to bank* — `send 1000 naira to 0123456789 GTBank`\n" .
            "📤 *Send crypto* — `send 50 USDT to 0x…` or a Stellar `C…` / phone\n" .
            "🔄 *Swap* — `swap 2 USDT to USDC`\n" .
            "📥 *Deposit address* — `receive`\n" .
            "📜 *History* — `history`\n" .
            "🔐 *Change PIN* — `change pin`\n" .
            "❌ *Cancel pending tx* — `cancel`\n" .
            "📋 *Main menu* — `menu`\n\n" .
            "PINs are entered on our secure web page — never type your PIN in this chat.\n\n" .
            'Questions? Email support@usesendra.com'
        );

        return StepResult::complete(['action' => 'help']);
    }
}
