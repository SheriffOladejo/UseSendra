<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\StellarReceive;
use AptosV2\Support\StellarWalletService;

final class CreateStellarStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        /** @var StellarWalletService $svc */
        $svc = $ctx->services->get('stellar_wallet');
        $this->reply($ctx, '⏳ Creating your Stellar wallet…');
        $result = $svc->provision($ctx->from, $ctx->user);
        if (empty($result['success'])) {
            $this->reply(
                $ctx,
                "❌ Couldn't create a Stellar wallet right now. Please try again in a minute, or email support@usesendra.com"
            );

            return StepResult::complete(['action' => 'stellar_create_failed']);
        }

        $already = !empty($result['already']);
        $this->reply(
            $ctx,
            $already ? '✅ You already have a Stellar wallet.' : '✅ *Your Stellar wallet is ready!*'
        );
        usleep(250000);
        StellarReceive::sendCopyableDeposit(
            $ctx->whatsapp(),
            $ctx->from,
            isset($result['stellar_deposit_memo']) ? (string) $result['stellar_deposit_memo'] : null
        );
        usleep(250000);
        $this->reply($ctx, 'Type *deposit* or *buy* to use it. Sends still leave from your Sendra Stellar wallet.');

        return StepResult::complete(['action' => 'stellar_created', 'already' => $already]);
    }
}
