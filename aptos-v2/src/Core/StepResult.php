<?php

declare(strict_types=1);

namespace AptosV2\Core;

/**
 * Explicit return contract for a step.
 *
 * This is the fix for the "leaky return contract" in the Logistics steps,
 * where handle() returned a string but most steps actually sent buttons /
 * lists / images directly and returned ''. Here the side-effects (sending
 * WhatsApp messages) stay inside the step, and the *return value* only
 * describes the state transition. The Kernel applies that transition.
 */
final class StepResult
{
    /**
     * @param array<string, mixed> $statePatch Merged onto existing flow state on Transition.
     * @param array<string, mixed> $meta       Diagnostic payload returned to the caller / logs.
     */
    private function __construct(
        public readonly StepOutcome $outcome,
        public readonly ?string $flow = null,
        public readonly ?string $step = null,
        public readonly array $statePatch = [],
        public readonly array $meta = [],
    ) {
    }

    /**
     * Stay on the current step (e.g. validation failed and we re-prompted).
     *
     * @param array<string, mixed> $meta
     */
    public static function stay(array $meta = []): self
    {
        return new self(StepOutcome::Stay, meta: $meta);
    }

    /**
     * Advance to another step, persisting a state patch.
     *
     * @param array<string, mixed> $statePatch
     * @param array<string, mixed> $meta
     */
    public static function transition(string $flow, string $step, array $statePatch = [], array $meta = []): self
    {
        return new self(StepOutcome::Transition, $flow, $step, $statePatch, $meta);
    }

    /**
     * Finish the flow and clear state.
     *
     * @param array<string, mixed> $meta
     */
    public static function complete(array $meta = []): self
    {
        return new self(StepOutcome::Complete, meta: $meta);
    }

    /**
     * Decline to handle — Kernel forwards to the legacy controller.
     *
     * @param array<string, mixed> $meta
     */
    public static function handOff(array $meta = []): self
    {
        return new self(StepOutcome::HandOff, meta: $meta);
    }
}
