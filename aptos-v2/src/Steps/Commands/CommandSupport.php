<?php

declare(strict_types=1);

namespace AptosV2\Steps\Commands;

use AptosV2\Core\StepContext;

/**
 * Shared helpers for one-shot command steps.
 */
trait CommandSupport
{
    protected function walletAddress(StepContext $ctx): ?string
    {
        $addr = $ctx->user['aptos_address'] ?? null;

        return is_string($addr) && trim($addr) !== '' ? trim($addr) : null;
    }

    protected function displayName(StepContext $ctx): string
    {
        $name = $ctx->user['username'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : 'there';
    }
}
