<?php

declare(strict_types=1);

namespace AptosV2\Core;

/**
 * What the Kernel should do after a step runs.
 */
enum StepOutcome: string
{
    /** Keep the user on the current step; state unchanged. */
    case Stay = 'stay';

    /** Persist new flow state and move to {flow, step}. */
    case Transition = 'transition';

    /** Flow finished — clear chat_context. */
    case Complete = 'complete';

    /** Not ours to handle — let the legacy controller take over. */
    case HandOff = 'handoff';
}
