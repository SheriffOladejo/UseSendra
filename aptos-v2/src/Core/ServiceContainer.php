<?php

declare(strict_types=1);

namespace AptosV2\Core;

/**
 * Minimal lazy service container (PSR-11 flavoured, no interface dependency).
 *
 * Steps never receive a fixed constructor argument list. Instead they reach
 * the handful of services they actually need through the {@see StepContext}.
 * This is the fix for the "rigid constructor" weakness in the Logistics
 * BaseStep, where every step was forced to accept the same 6 dependencies.
 *
 * Register factories once at boot; instances are created on first use and
 * memoised for the lifetime of the request.
 */
final class ServiceContainer
{
    /** @var array<string, callable(ServiceContainer): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * @param callable(ServiceContainer): mixed $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Register an already-constructed value.
     */
    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new \RuntimeException("Service '{$id}' is not registered in the container.");
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}
