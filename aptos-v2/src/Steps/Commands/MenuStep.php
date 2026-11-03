<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\AbstractStep;
use AptosV2\Core\StepContext;
use AptosV2\Core\StepResult;
use AptosV2\Services\Buy\QuoteService;

final class MenuStep extends AbstractStep
{
    use CommandSupport;

    public function handle(StepContext $ctx): StepResult
    {
        /** @var QuoteService $quotes */
        $quotes = $ctx->services->get('buy_quote');
        $quotes->cancelActiveForPhone($ctx->from);

        $name = $this->displayName($ctx);

        if ((int) ($ctx->user['is_logistics'] ?? 0) === 1) {
            $this->reply($ctx, "Welcome back, {$name}! 🚚\n\n");

            return StepResult::complete(['action' => 'greeting']);
        }

        $this->buttons($ctx, MenuContent::body($name), MenuContent::buttons());

        return StepResult::complete(['action' => 'greeting']);
    }
}
