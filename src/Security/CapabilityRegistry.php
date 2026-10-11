<?php

declare(strict_types=1);

namespace Beacon\Security;

/**
 * Every capability Beacon knows about. A capability that is not registered
 * here is denied, even if it is listed in the configuration or declared by a
 * tool or inspector.
 *
 * Beacon's {@see InspectionCapability} cases are always registered. Packages
 * that add inspectors register their own read capabilities explicitly.
 */
final class CapabilityRegistry
{
    /**
     * @var array<string, string> description keyed by capability, e.g. "routes:read"
     */
    private array $capabilities = [];

    public function __construct()
    {
        foreach (InspectionCapability::cases() as $case) {
            $this->capabilities[(string) $case->capability()] = $case->description();
        }
    }

    /**
     * @throws \InvalidArgumentException when the capability is not read-only
     */
    public function register(Capability $capability, string $description): void
    {
        if (! $capability->isReadOnly()) {
            throw new \InvalidArgumentException("Capability [{$capability}] cannot be registered: Beacon is read-only.");
        }

        $this->capabilities[(string) $capability] = $description;
    }

    public function has(Capability $capability): bool
    {
        return isset($this->capabilities[(string) $capability]);
    }

    /**
     * @return array<string, string> description keyed by capability
     */
    public function all(): array
    {
        return $this->capabilities;
    }
}
