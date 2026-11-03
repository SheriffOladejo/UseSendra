<?php

declare(strict_types=1);

namespace AptosV2\Services;

/**
 * Outcome of fixed-NGN send-naira sizing (no WhatsApp side effects).
 *
 * @phpstan-type SizingData array{
 *   crypto_amount: string,
 *   receive_ngn_display: string,
 *   rate_hint?: ?string,
 *   fee_hint?: ?string,
 *   oneclick_est_usdc?: float,
 *   oneclick_dry_quote_data?: array<string, mixed>,
 *   oneclick_quote_data?: array<string, mixed>,
 *   oneclick_live_quote_data?: array<string, mixed>,
 *   receive_ngn_pool?: float,
 *   panora_est_usdc?: float,
 *   panora_quote_data?: array<string, mixed>,
 *   panora_centiiv_usdc?: float
 * }
 */
final class NairaSizingResult
{
    public const LEGACY_REQUIRED = 'legacy_required';

    /**
     * @param SizingData $data
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $errorCode,
        public readonly ?string $userMessage,
        public readonly array $data,
    ) {
    }

    public function needsLegacyFallback(): bool
    {
        return $this->errorCode === self::LEGACY_REQUIRED;
    }

    /**
     * @param SizingData $data
     */
    public static function ok(array $data): self
    {
        return new self(true, null, null, $data);
    }

    public static function fail(string $code, string $userMessage): self
    {
        return new self(false, $code, $userMessage, []);
    }

    public static function legacyRequired(): self
    {
        return new self(false, self::LEGACY_REQUIRED, null, []);
    }
}
