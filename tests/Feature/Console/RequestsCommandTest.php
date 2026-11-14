<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\RequestsCommand;
use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestStore;
use Beacon\Tests\Fixtures\ArrayRequestStore;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class RequestsCommandTest extends TestCase
{
    #[Test]
    public function it_prints_json_request_records(): void
    {
        $this->application()->make(RequestStore::class)->write(new RequestObservation(
            requestId: 'request-id-cmd-0001',
            correlationId: 'request-id-cmd-0001',
            method: 'GET',
            routeName: 'probe.show',
            routeUri: '/probe',
            action: 'Closure',
            status: 200,
            startedAt: '2026-11-14T12:00:00.000+00:00',
            finishedAt: '2026-11-14T12:00:00.010+00:00',
            durationMs: 10,
            environment: 'testing',
            hadException: false,
            exceptionId: null,
            exceptionClass: null,
        ));

        $exit = Artisan::call('beacon:requests', ['--json' => true, '--limit' => '10']);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['summary'] ?? null);
        $this->assertIsArray($payload['requests'] ?? null);
        $this->assertSame(1, $payload['summary']['returned'] ?? null);
        $this->assertIsArray($payload['requests'][0] ?? null);
        $this->assertSame('GET', $payload['requests'][0]['method'] ?? null);
        $this->assertSame('/probe', $payload['requests'][0]['route_uri'] ?? null);
    }

    #[Test]
    public function it_denies_callers_without_the_requests_capability(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['application']);

        $exit = Artisan::call('beacon:requests', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(RequestsCommand::EXIT_DENIED, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('not_allowed', $payload['error'] ?? null);
    }
    protected function setUp(): void
    {
        parent::setUp();

        $this->application()->instance(RequestStore::class, new ArrayRequestStore());
        config([
            'beacon.requests.collection.enabled' => true,
            'beacon.security.allowed_capabilities' => ['requests'],
        ]);
    }
}
