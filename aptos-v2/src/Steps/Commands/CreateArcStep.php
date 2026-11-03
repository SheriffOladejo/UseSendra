<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Support\ArcWalletService;

final class CreateArcStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        /** @var ArcWalletService $svc */
        $svc = $ctx->services->get('arc_wallet');
        $this->reply($ctx, '⏳ Creating your Arc wallet…');
        $result = $svc->provision($ctx->from, $ctx->user);
        if (empty($result['success'])) {
            $this->reply(
                $ctx,
                "❌ Couldn't create an Arc wallet right now. Please try again in a minute, or email support@usesendra.com"
            );

            return StepResult::complete(['action' => 'arc_create_failed']);
        }

        $already = !empty($result['already']);
        $addr = (string) ($result['arc_address'] ?? '');
        $this->reply(
            $ctx,
            ($already ? '✅ You already have an Arc wallet.' : '✅ *Your Arc wallet is ready!*')
            . "\n\nSend *USDC on Arc* to:"
        );
        usleep(250000);
        $this->reply($ctx, $addr);

        return StepResult::complete(['action' => 'arc_create', 'already' => $already]);
    }
}
