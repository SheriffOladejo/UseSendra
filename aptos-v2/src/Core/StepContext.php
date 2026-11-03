<?php

declare(strict_types=1);

namespace AptosV2\Core;

use App\Services\WhatsAppService;

/**
 * Immutable value object handed to every step.
 *
 * It carries the incoming message, the resolved user row, the current flow
 * state (decoded `users.chat_context`) and access to shared services via the
 * container. Steps pull only what they need — this is what removes the rigid
 * constructor coupling that the Logistics BaseStep suffered from.
 */
final class StepContext
{
    /**
     * @param array<string, mixed> $user  Resolved user row from the Aptos DB.
     * @param array<string, mixed> $state Decoded flow state (chat_context JSON).
     */
    public function __construct(
        public readonly string $from,
        public readonly string $message,
        public readonly string $messageLower,
        public readonly array $user,
        public readonly array $state,
        public readonly string $displayName,
        public readonly ?string $inboundWamid,
        public readonly ServiceContainer $services,
    ) {
    }

    public function whatsapp(): WhatsAppService
    {
        return $this->services->get('whatsapp');
    }

    public function db(): \PDO
    {
        return $this->services->get('db');
    }

    /**
     * Read a value out of the current flow state.
     */
    public function stateValue(string $key, mixed $default = null): mixed
    {
        return $this->state[$key] ?? $default;
    }

    /**
     * Return a copy of this context with a different message payload.
     * Handy when a step wants to re-dispatch normalized input.
     */
    public function withMessage(string $message): self
    {
        return new self(
            $this->from,
            $message,
            strtolower(trim($message)),
            $this->user,
            $this->state,
            $this->displayName,
            $this->inboundWamid,
            $this->services,
        );
    }
}
