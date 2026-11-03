<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\AptosBalanceService;

final class BalanceStep extends AbstractStep
{
    use CommandSupport;

    public function handle(StepContext $ctx): StepResult
    {
        $wallet = $this->walletAddress($ctx);
        if ($wallet === null) {
            $this->reply($ctx, "❌ No wallet found for your account.\n\nType *hi* to get started.");

            return StepResult::complete(['action' => 'balance', 'error' => 'no_wallet']);
        }

        $this->reply($ctx, '💰 Fetching your balance...');

        /** @var AptosBalanceService $balances */
        $balances = $ctx->services->get('aptos_balance');
        $row = $balances->fetch($wallet);

        if ($row === null) {
            $this->reply($ctx, '❌ Failed to fetch balance. Please try again.');

            return StepResult::complete(['action' => 'balance', 'error' => 'fetch_failed']);
        }

        $usdc = self::parseAmount($row['USDC'] ?? '0');
        $usdt = self::parseAmount($row['USDT'] ?? '0');

        $stellarAddr = \AptosV2\Support\StellarAddress::fromUser($ctx->user);
        if ($stellarAddr !== null) {
            /** @var \AptosV2\Support\StellarBalanceService $stellarBal */
            $stellarBal = $ctx->services->get('stellar_balance');
            $srow = $stellarBal->fetch($stellarAddr);
            if ($srow !== null) {
                $usdc += self::parseAmount($srow['USDC'] ?? '0');
                $usdt += self::parseAmount($srow['USDT'] ?? '0');
            }
        }

        $arcAddr = \AptosV2\Support\ArcAddress::fromUser($ctx->user);
        if ($arcAddr !== null) {
            /** @var \AptosV2\Support\ArcBalanceService $arcBal */
            $arcBal = $ctx->services->get('arc_balance');
            $arow = $arcBal->fetch($arcAddr);
            if ($arow !== null) {
                $usdc += self::parseAmount($arow['USDC'] ?? '0');
            }
        }

        $usdcDisp = number_format($usdc, 2);
        $usdtDisp = number_format($usdt, 2);

        $this->reply(
            $ctx,
            "*💰 Your Wallet Balance*\n\n" .
            "💵 USDC: *\${$usdcDisp}*\n" .
            "💵 USDT: *\${$usdtDisp}*\n\n" .
            '_Updated: ' . date('M d, Y g:i A') . '_'
        );

        return StepResult::complete(['action' => 'balance']);
    }

    private static function parseAmount(string $raw): float
    {
        return (float) str_replace(',', '', $raw);
    }
}
