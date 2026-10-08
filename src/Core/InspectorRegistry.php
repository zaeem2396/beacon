<?php

declare(strict_types=1);

namespace Beacon\Core;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Exceptions\InspectorNotFoundException;
use Illuminate\Contracts\Container\Container;

/**
 * Named inspectors, resolved lazily from the container so registering an
 * inspector never runs it.
 */
final class InspectorRegistry
{
    /**
     * @var array<string, class-string<Inspector<Data>>>
     */
    private array $inspectors = [];

    public function __construct(
        private readonly Container $container,
        private readonly BeaconConfig $config,
    ) {
    }

    /**
     * @param class-string<Inspector<Data>> $inspector
     */
    public function register(string $name, string $inspector): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid inspector name [{$name}].");
        }

        if (! is_subclass_of($inspector, Inspector::class)) {
            throw new \InvalidArgumentException("[{$inspector}] must implement " . Inspector::class . '.');
        }

        $this->inspectors[$name] = $inspector;
    }

    public function has(string $name): bool
    {
        return isset($this->inspectors[$name]);
    }

    public function enabled(string $name): bool
    {
        return $this->has($name) && $this->config->inspectorEnabled($name);
    }

    /**
     * Names of registered and enabled inspectors, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_filter(array_keys($this->inspectors), $this->enabled(...)));
    }

    /**
     *
     * @throws InspectorNotFoundException
     *
     * @return Inspector<Data>
     */
    public function get(string $name): Inspector
    {
        if (! $this->has($name)) {
            throw InspectorNotFoundException::named($name);
        }

        if (! $this->enabled($name)) {
            throw InspectorNotFoundException::disabled($name);
        }

        return $this->container->make($this->inspectors[$name]);
    }
}
