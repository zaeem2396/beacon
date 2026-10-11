<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Mcp\BeaconMcpServiceProvider;
use Beacon\Mcp\ServerRegistration;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Registrar;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('mcp')]
final class DisabledByDefaultTest extends McpTestCase
{
    #[Test]
    public function it_loads_the_beacon_mcp_service_provider(): void
    {
        $this->assertArrayHasKey(BeaconMcpServiceProvider::class, $this->application()->getLoadedProviders());
    }

    #[Test]
    public function the_server_is_not_registered_by_default(): void
    {
        $registration = $this->application()->make(ServerRegistration::class);

        $this->assertFalse($registration->registered());
        $this->assertStringContainsString('disabled', $registration->decide()->reason);
        $this->assertSame([], $this->application()->make(Registrar::class)->servers());
    }

    #[Test]
    public function the_server_cannot_be_started_by_default(): void
    {
        $this->assertSame(1, Artisan::call('mcp:start', ['handle' => ServerRegistration::HANDLE]));
        $this->assertStringContainsString('[beacon] not found', Artisan::output());
    }
}
