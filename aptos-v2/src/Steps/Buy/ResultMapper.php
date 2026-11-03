<?php

declare(strict_types=1);

namespace AptosV2\Steps\Buy;

use AptosV2\Core\StepResult;

/**
 * Maps buy service outcomes to StepResult.
 */
final class ResultMapper
{
    /**
     * @param array{outcome: string, flow?: string, step?: string, patch?: array<string, mixed>, meta?: array<string, mixed>} $result
     */
    public static function fromService(array $result): StepResult
    {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $outcome = (string) ($result['outcome'] ?? 'stay');

        if ($outcome === 'complete') {
            return StepResult::complete($meta);
        }
        if ($outcome === 'transition') {
            return StepResult::transition(
                (string) ($result['flow'] ?? 'buy'),
                (string) ($result['step'] ?? ''),
                is_array($result['patch'] ?? null) ? $result['patch'] : [],
                $meta
            );
        }

        return StepResult::stay($meta);
    }
}
