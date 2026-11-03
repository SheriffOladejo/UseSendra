<?php

declare(strict_types=1);

namespace AptosV2\Core;

/**
 * Resolves a `{flow, step}` pair to a step instance.
 *
 * The map is a plain array of "flow:step" => StepClass::class (see
 * config/steps.php), mirroring the Logistics StepRouter but keyed by flow so
 * the same step name can exist in different flows without collision.
 */
final class StepRouter
{
    /** @var array<string, class-string<StepInterface>> */
    private array $map;

    /**
     * @param array<string, class-string<StepInterface>> $map
     */
    public function __construct(array $map)
    {
        $this->map = $map;
    }

    public function has(string $flow, string $step): bool
    {
        return isset($this->map[self::key($flow, $step)]);
    }

    public function resolve(string $flow, string $step): ?StepInterface
    {
        $class = $this->map[self::key($flow, $step)] ?? null;
        if ($class === null) {
            return null;
        }

        $instance = new $class();
        if (!$instance instanceof StepInterface) {
            throw new \RuntimeException("Mapped class {$class} does not implement StepInterface.");
        }

        return $instance;
    }

    private static function key(string $flow, string $step): string
    {
        return $flow . ':' . $step;
    }
}
