<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Security;

use Beacon\Core\BeaconConfig;
use Beacon\Security\AccessMode;
use Beacon\Security\Capability;
use Beacon\Security\CapabilityPolicy;
use Beacon\Security\CapabilityRegistry;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapabilityPolicyTest extends TestCase
{
    #[Test]
    public function every_inspection_capability_is_registered_and_read_only(): void
    {
        $registry = new CapabilityRegistry();

        foreach (InspectionCapability::cases() as $case) {
            $this->assertTrue($case->capability()->isReadOnly());
            $this->assertTrue($registry->has($case->capability()));
            $this->assertNotSame('', $case->description());
        }

        $this->assertCount(count(InspectionCapability::cases()), $registry->all());
        $this->assertFalse($registry->has(new Capability('routes', AccessMode::Write)));
    }

    #[Test]
    public function an_empty_allowlist_denies_everything(): void
    {
        $this->assertFalse($this->policy([])->evaluate(Principal::local(), Capability::read('routes'))->allowed);
    }

    #[Test]
    public function only_listed_capabilities_are_allowed(): void
    {
        $policy = $this->policy(['routes']);

        $this->assertTrue($policy->evaluate(Principal::local(), Capability::read('routes'))->allowed);
        $this->assertFalse($policy->evaluate(Principal::local(), Capability::read('config'))->allowed);
    }

    #[Test]
    public function the_wildcard_only_covers_registered_capabilities(): void
    {
        $policy = $this->policy(['*', 'shell']);

        $this->assertTrue($policy->evaluate(Principal::local(), Capability::read('config'))->allowed);
        $this->assertFalse($policy->evaluate(Principal::local(), Capability::read('shell'))->allowed);
        $this->assertFalse($policy->evaluate(Principal::local(), Capability::read('sql'))->allowed);
    }

    #[Test]
    public function mutating_capabilities_are_denied_before_anything_else(): void
    {
        $decision = $this->policy(['*', 'queue'])->evaluate(Principal::local(), new Capability('queue', AccessMode::Write));

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('read-only', $decision->reason);
    }

    #[Test]
    public function mutating_capabilities_cannot_be_registered(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CapabilityRegistry())->register(new Capability('cache', AccessMode::Write), 'Clear caches.');
    }

    /**
     * @param list<string> $allowed
     */
    private function policy(array $allowed): CapabilityPolicy
    {
        $config = new BeaconConfig(new Repository(['beacon' => ['security' => ['allowed_capabilities' => $allowed]]]), 'testing');

        return new CapabilityPolicy($config, new CapabilityRegistry());
    }
}
