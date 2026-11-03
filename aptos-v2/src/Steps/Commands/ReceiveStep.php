<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;

final class ReceiveStep extends AbstractStep
{
    use CommandSupport;

    public function handle(StepContext $ctx): StepResult
    {
        $wallet = $this->walletAddress($ctx);
        if ($wallet === null) {
            $this->reply($ctx, "❌ No wallet found. Type *hi* to create one.");

            return StepResult::complete(['action' => 'receive', 'error' => 'no_wallet']);
        }

        $this->reply($ctx, '💳 *Your Deposit Address*');
        usleep(300000);
        $this->reply($ctx, $wallet);
        usleep(300000);
        $this->reply(
            $ctx,
            "*📋 Aptos:*\n" .
            "• Copy the address above\n" .
            "• Send *USDC or USDT* on the *Aptos network* only\n" .
            "• Wrong network = lost funds"
        );

        $memo = \AptosV2\Support\StellarReceive::memoFromUser($ctx->user);
        if ($memo === null && \AptosV2\Support\StellarWalletService::hasAddress($ctx->user)) {
            /** @var \AptosV2\Support\StellarWalletService $svc */
            $svc = $ctx->services->get('stellar_wallet');
            $out = $svc->provision($ctx->from, $ctx->user);
            $memo = isset($out['stellar_deposit_memo']) ? (string) $out['stellar_deposit_memo'] : null;
        }
        if ($memo !== null && $memo !== '') {
            usleep(250000);
            $this->reply($ctx, '💳 *Your Stellar deposit*');
            usleep(250000);
            \AptosV2\Support\StellarReceive::sendCopyableDeposit($ctx->whatsapp(), $ctx->from, $memo);
        }

        $arcAddr = \AptosV2\Support\ArcAddress::fromUser($ctx->user);
        if ($arcAddr === null) {
            /** @var \AptosV2\Support\ArcWalletService $arc */
            $arc = $ctx->services->get('arc_wallet');
            $out = $arc->provision($ctx->from, $ctx->user);
            $arcAddr = isset($out['arc_address']) ? (string) $out['arc_address'] : null;
        }
        if ($arcAddr !== null && $arcAddr !== '') {
            usleep(250000);
            $this->reply($ctx, '💳 *Your Arc deposit*');
            usleep(250000);
            $this->reply($ctx, $arcAddr);
            usleep(250000);
            $this->reply($ctx, "Send *USDC on Arc* only.\nWrong network = lost funds.");
        }

        return StepResult::complete(['action' => 'receive']);
    }
}
