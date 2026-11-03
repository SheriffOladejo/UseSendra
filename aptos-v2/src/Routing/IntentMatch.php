<?php

declare(strict_types=1);

namespace AptosV2\Routing;

/**
 * Result of matching an inbound message against a flow-entry intent.
 * Tells the Kernel which {flow, step} to start fresh.
 */
final class IntentMatch
{
    public function __construct(
        public readonly string $flow,
        public readonly string $step,
    ) {
    }
}
