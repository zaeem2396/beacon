<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Data\SlowRequestInspection;
use Beacon\Inspectors\SlowRequestInspector;
use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestStore;
use Beacon\Requests\SlowRank;
use Beacon\Tests\Fixtures\ArrayRequestStore;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SlowRequestInspectorTest extends TestCase
{
    private ArrayRequestStore $store;

    #[Test]
    public function it_reports_disabled_collection(): void
    {
        config()->set('beacon.requests.collection.enabled', false);

        $inspection = $this->application()->make(SlowRequestInspector::class)->inspect([
            'since' => '2026-11-14T00:00:00.000+00:00',
            'until' => '2026-11-15T00:00:00.000+00:00',
        ]);

        $this->assertInstanceOf(SlowRequestInspection::class, $inspection);
        $this->assertSame([], $inspection->requests);
        $this->assertSame('disabled', $inspection->status);
        $this->assertSame('Request collection is disabled.', $inspection->summary);
    }

    #[Test]
    public function it_reports_unavailable_storage(): void
    {
        config()->set('beacon.requests.collection.enabled', true);
        $this->application()->instance(RequestStore::class, new ArrayRequestStore(available: false));

        $inspection = $this->application()->make(SlowRequestInspector::class)->inspect([
            'since' => '2026-11-14T00:00:00.000+00:00',
            'until' => '2026-11-15T00:00:00.000+00:00',
        ]);

        $this->assertSame('unavailable', $inspection->status);
        $this->assertSame('Request storage is unavailable.', $inspection->summary);
    }

    #[Test]
    public function it_detects_slow_requests_without_bodies_or_secrets(): void
    {
        config()->set('beacon.requests.slow.threshold_ms', 1000);

        $this->store->write(new RequestObservation(
            requestId: 'request-id-fast--0001',
            correlationId: 'request-id-fast--0001',
            method: 'GET',
            routeName: 'probe.fast',
            routeUri: '/probe/fast',
            action: 'Closure',
            status: 200,
            startedAt: '2026-11-14T12:00:00.000+00:00',
            finishedAt: '2026-11-14T12:00:00.500+00:00',
            durationMs: 500,
            environment: 'testing',
            hadException: false,
            exceptionId: null,
            exceptionClass: null,
        ));
        $this->store->write(new RequestObservation(
            requestId: 'request-id-slow--0002',
            correlationId: 'request-id-slow--0002',
            method: 'POST',
            routeName: 'probe.slow',
            routeUri: '/probe/slow',
            action: 'Closure',
            status: 500,
            startedAt: '2026-11-14T12:01:00.000+00:00',
            finishedAt: '2026-11-14T12:01:03.000+00:00',
            durationMs: 3000,
            environment: 'testing',
            hadException: true,
            exceptionId: 'exc-0002',
            exceptionClass: \RuntimeException::class,
        ));

        $inspection = $this->application()->make(SlowRequestInspector::class)->inspect([
            'since' => '2026-11-14T00:00:00.000+00:00',
            'until' => '2026-11-15T00:00:00.000+00:00',
        ]);

        $this->assertInstanceOf(SlowRequestInspection::class, $inspection);
        $this->assertSame('ready', $inspection->status);
        $this->assertSame(1, $inspection->returned);
        $this->assertSame(1, $inspection->slowCount);
        $this->assertSame(1, $inspection->inspected);
        $this->assertSame('request-id-slow--0002', $inspection->requests[0]->requestId);
        $this->assertSame(2000, $inspection->requests[0]->excessMs);
        $this->assertSame(SlowRank::Medium, $inspection->requests[0]->rank);
        $this->assertSame('exception', $inspection->requests[0]->correlationKind);
        $this->assertTrue($inspection->requests[0]->hadException);

        $payload = $inspection->toArray();
        $encoded = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('body=', $encoded);
        $this->assertStringNotContainsString('Authorization', $encoded);
        $this->assertStringNotContainsString('cookie', $encoded);
        $this->assertStringNotContainsString('payment-token', $encoded);
        $this->assertIsArray($payload['requests'] ?? null);
        $this->assertIsArray($payload['requests'][0] ?? null);
        $this->assertArrayNotHasKey('body', $payload['requests'][0]);
        $this->assertArrayNotHasKey('headers', $payload['requests'][0]);
        $this->assertArrayNotHasKey('query', $payload['requests'][0]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ArrayRequestStore();
        $this->application()->instance(RequestStore::class, $this->store);

        config([
            'beacon.requests.collection.enabled' => true,
            'beacon.security.allowed_capabilities' => ['slow_requests'],
        ]);
    }
}
