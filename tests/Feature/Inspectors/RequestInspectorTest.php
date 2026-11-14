<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Data\RequestInspection;
use Beacon\Inspectors\RequestInspector;
use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestStore;
use Beacon\Tests\Fixtures\ArrayRequestStore;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequestInspectorTest extends TestCase
{
    private ArrayRequestStore $store;

    #[Test]
    public function it_reports_disabled_collection(): void
    {
        config()->set('beacon.requests.collection.enabled', false);

        $inspection = $this->application()->make(RequestInspector::class)->inspect();

        $this->assertInstanceOf(RequestInspection::class, $inspection);
        $this->assertSame([], $inspection->requests);
        $this->assertSame('disabled', $inspection->summary->collection);
        $this->assertSame('Request collection is disabled.', $inspection->summaryText);
    }

    #[Test]
    public function it_reports_unavailable_storage(): void
    {
        config()->set('beacon.requests.collection.enabled', true);
        $this->application()->instance(RequestStore::class, new ArrayRequestStore(available: false));

        $inspection = $this->application()->make(RequestInspector::class)->inspect();

        $this->assertSame('unavailable', $inspection->summary->collection);
        $this->assertSame('Request storage is unavailable.', $inspection->summaryText);
    }

    #[Test]
    public function it_reports_an_empty_window(): void
    {
        $inspection = $this->application()->make(RequestInspector::class)->inspect();

        $this->assertSame([], $inspection->requests);
        $this->assertSame('enabled', $inspection->summary->collection);
        $this->assertSame(0, $inspection->summary->inspected);
        $this->assertSame('No request records matched the inspected window.', $inspection->summaryText);
    }

    #[Test]
    public function it_filters_and_pages_records(): void
    {
        foreach ([
            ['request-id-aaaa-0001', 'GET', '/alpha', 200, false],
            ['request-id-bbbb-0002', 'POST', '/beta', 500, true],
            ['request-id-cccc-0003', 'GET', '/gamma', 200, false],
        ] as [$id, $method, $uri, $status, $exception]) {
            $this->store->write(new RequestObservation(
                requestId: $id,
                correlationId: $id,
                method: $method,
                routeName: null,
                routeUri: $uri,
                action: null,
                status: $status,
                startedAt: '2026-11-14T12:00:00.000+00:00',
                finishedAt: '2026-11-14T12:00:00.050+00:00',
                durationMs: 50,
                environment: 'testing',
                hadException: $exception,
                exceptionId: $exception ? 'exc-' . substr($id, -4) : null,
                exceptionClass: $exception ? \RuntimeException::class : null,
            ));
        }

        $filtered = $this->application()->make(RequestInspector::class)->inspect([
            'method' => 'GET',
            'limit' => 1,
            'page' => 1,
        ]);
        $this->assertInstanceOf(RequestInspection::class, $filtered);
        $this->assertCount(1, $filtered->requests);
        $this->assertSame('GET', $filtered->requests[0]->method);
        $this->assertSame(2, $filtered->summary->inspected);

        $pageTwo = $this->application()->make(RequestInspector::class)->inspect([
            'method' => 'GET',
            'limit' => 1,
            'page' => 2,
        ]);
        $this->assertCount(1, $pageTwo->requests);
        $this->assertNotSame($filtered->requests[0]->requestId, $pageTwo->requests[0]->requestId);

        $exceptions = $this->application()->make(RequestInspector::class)->inspect([
            'had_exception' => true,
        ]);
        $this->assertCount(1, $exceptions->requests);
        $this->assertTrue($exceptions->requests[0]->hadException);
        $this->assertSame(500, $exceptions->requests[0]->status);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ArrayRequestStore();
        $this->application()->instance(RequestStore::class, $this->store);

        config([
            'beacon.requests.collection.enabled' => true,
            'beacon.security.allowed_capabilities' => ['requests'],
        ]);
    }
}
