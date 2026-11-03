<?php

declare(strict_types=1);

namespace AptosV2\Core;

/**
 * A single unit of conversation logic.
 *
 * Steps are stateless: everything they need arrives in the {@see StepContext},
 * and everything they decide leaves in the {@see StepResult}. Because they take
 * no constructor arguments, the router can instantiate any step with `new`.
 */
interface StepInterface
{
    public function handle(StepContext $ctx): StepResult;
}
