<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Buy\OrderService;

/**
 * Order creation in flight (`await_opening_order`).
 */
final class OpeningOrderStep extends AbstractStep
{
    public function handle(StepContext $ctx): StepResult
    {
        if ($exit = FlowSupport::earlyExit($ctx)) {
            return $exit;
        }

        /** @var OrderService $orders */
        $orders = $ctx->services->get('buy_order');
        $result = $orders->handleOpeningOrderStep(
            $ctx->from,
            $ctx->user,
            $ctx->state,
            $ctx->messageLower
        );

        return ResultMapper::fromService($result);
    }
}
