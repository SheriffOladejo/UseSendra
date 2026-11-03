<?php

declare(strict_types=1);

namespace AptosV2\Core;

/**
 * Convenience base for steps. Optional — implementing {@see StepInterface}
 * directly is fine. Provides small helpers for the common WhatsApp side-effects
 * so individual steps stay focused on flow logic.
 */
abstract class AbstractStep implements StepInterface
{
    protected function reply(StepContext $ctx, string $text): void
    {
        $ctx->whatsapp()->sendMessage($ctx->from, $text);
    }

    /**
     * @param array<string, string> $buttons id => label (max 3 for WhatsApp).
     */
    protected function buttons(StepContext $ctx, string $body, array $buttons): void
    {
        $ctx->whatsapp()->sendButtonMessage($ctx->from, $body, $buttons);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    protected function list(StepContext $ctx, string $body, string $buttonLabel, array $rows): void
    {
        $ctx->whatsapp()->sendListMessage($ctx->from, $body, $buttonLabel, $rows);
    }

    /**
     * True when the user typed a cancel / abort intent.
     */
    protected function wantsCancel(StepContext $ctx): bool
    {
        return in_array($ctx->messageLower, ['cancel', '/cancel', 'stop'], true);
    }
}
