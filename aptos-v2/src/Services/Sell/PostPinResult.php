<?php

declare(strict_types=1);

namespace AptosV2\Services\Sell;

/**
 * Outcome of v2 web PIN confirmation (no WhatsApp side effects on wrong PIN when disabled).
 */
final class PostPinResult
{
    public const REASON_LEGACY_REQUIRED = 'legacy_required';
    public const REASON_DUPLICATE_PIN = 'duplicate_pin';
    public const REASON_NO_PRIVATE_KEY = 'no_private_key';

    private function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function fail(string $error, ?string $reason = null): self
    {
        return new self(false, $error, $reason);
    }

    public function needsLegacyFallback(): bool
    {
        return $this->reason === self::REASON_LEGACY_REQUIRED;
    }
}
