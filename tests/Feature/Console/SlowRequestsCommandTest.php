<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\SlowRequestsCommand;
use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestStore;
use Beacon\Tests\Fixtures\ArrayRequestStore;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class SlowRequestsCommandTest extends TestCase
{
    #[Test]
    public function it_prints_json_slow_request_records(): void
    {
        config()->set('beacon.requests.slow.threshold_ms', 1000);

        $this->application()->make(RequestStore::class)->write(new RequestObservation(
            requestId: 'request-id-cmd-slow01',
            correlationId: 'request-id-cmd-slow01',
            method: 'GET',
            routeName: 'probe.slow',
            routeUri: '/probe/slow',
            action: 'Closure',
            status: 200,
            startedAt: '2026-11-14T12:00:00.000+00:00',
            finishedAt: '2026-11-14T12:00:02.500+00:00',
            durationMs: 2500,
            environment: 'testing',
            hadException: false,
            exceptionId: null,
            exceptionClass: null,
        ));

        $exit = Artisan::call('beacon:slow-requests', [
            '--json' => true,
            '--since' => '2026-11-14T00:00:00.000+00:00',
            '--until' => '2026-11-15T00:00:00.000+00:00',
            '--limit' => '10',
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['returned'] ?? null);
        $this->assertSame(1, $payload['slow_count'] ?? null);
        $this->assertIsArray($payload['requests'] ?? null);
        $this->assertIsArray($payload['requests'][0] ?? null);
        $this->assertSame('GET', $payload['requests'][0]['method'] ?? null);
        $this->assertSame('/probe/slow', $payload['requests'][0]['route_uri'] ?? null);
        $this->assertSame(1500, $payload['requests'][0]['excess_ms'] ?? null);
        $this->assertSame('none', $payload['requests'][0]['correlation_kind'] ?? null);
    }

    #[Test]
    public function it_denies_callers_without_the_slow_requests_capability(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['requests']);

        $exit = Artisan::call('beacon:slow-requests', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(SlowRequestsCommand::EXIT_DENIED, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('not_allowed', $payload['error'] ?? null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->application()->instance(RequestStore::class, new ArrayRequestStore());
        config([
            'beacon.requests.collection.enabled' => true,
            'beacon.security.allowed_capabilities' => ['slow_requests'],
        ]);
    }
}
