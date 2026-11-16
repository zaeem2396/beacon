<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\InvestigationContext;
use Beacon\Inspectors\InvestigationContextInspector;
use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestStore;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\ArrayRequestStore;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class InvestigationContextInspectorTest extends TestCase
{
    private ArrayRequestStore $store;

    #[Test]
    public function it_builds_a_context_for_a_request_id_with_missing_history_notes(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['investigation_context']);
        config()->set('beacon.requests.collection.enabled', true);
        config()->set('beacon.requests.slow.threshold_ms', 1000);

        $this->store->write(new RequestObservation(
            requestId: 'request-id-invest-0001',
            correlationId: 'request-id-invest-0001',
            method: 'GET',
            routeName: 'orders.show',
            routeUri: '/orders/{order}',
            action: 'OrderController@show',
            status: 500,
            startedAt: '2026-11-14T12:00:00.000+00:00',
            finishedAt: '2026-11-14T12:00:02.500+00:00',
            durationMs: 2500,
            environment: 'testing',
            hadException: true,
            exceptionId: 'exc-invest-0001',
            exceptionClass: \RuntimeException::class,
        ));

        $context = $this->application()->make(InvestigationContextInspector::class)->inspect([
            'request_id' => 'request-id-invest-0001',
            'since' => '2026-11-14T00:00:00.000+00:00',
            'until' => '2026-11-15T00:00:00.000+00:00',
        ]);

        $this->assertInstanceOf(InvestigationContext::class, $context);
        $this->assertSame(1, $context->schemaVersion);
        $this->assertSame('request', $context->primarySubject->kind->value);
        $this->assertNotSame([], $context->requests);
        $this->assertNotSame([], $context->slowRequests);
        $this->assertNotSame([], $context->missingEvidence);

        $codes = array_map(static fn ($note): string => $note->code, $context->missingEvidence);
        $this->assertContains('deployment_history_unavailable', $codes);
        $this->assertContains('configuration_change_history_unavailable', $codes);

        $payload = $context->toArray();
        $encoded = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Authorization', $encoded);
        $this->assertStringNotContainsString('"body"', $encoded);
        $this->assertStringNotContainsString('cookie', strtolower($encoded));
        $this->assertArrayHasKey('timeline', $payload);
        $this->assertArrayHasKey('leads', $payload);
        $this->assertArrayHasKey('correlations', $payload);
        $this->assertArrayHasKey('source_statuses', $payload);
    }

    #[Test]
    public function it_survives_a_disabled_optional_source(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['investigation_context']);
        config()->set('beacon.inspectors.failed_jobs.enabled', false);
        config()->set('beacon.requests.collection.enabled', false);

        $context = $this->application()->make(InvestigationContextInspector::class)->inspect([
            'since' => '2026-11-14T00:00:00.000+00:00',
            'until' => '2026-11-15T00:00:00.000+00:00',
        ]);

        $this->assertInstanceOf(InvestigationContext::class, $context);
        $statuses = [];

        foreach ($context->sourceStatuses as $status) {
            $statuses[$status->source] = $status->status;
        }

        $this->assertSame('disabled', $statuses['failed_jobs'] ?? null);
        $this->assertSame('disabled', $statuses['requests'] ?? null);
        $this->assertContains($context->completeness, ['partial', 'limited', 'complete']);
    }

    #[Test]
    public function beacon_inspect_requires_the_investigation_context_capability(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['requests']);

        $this->expectException(\Beacon\Exceptions\AuthorizationException::class);
        $this->application()->make(Beacon::class)->inspect(
            Principal::local(),
            InvestigationContextInspector::NAME,
            [],
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ArrayRequestStore();
        $this->application()->instance(RequestStore::class, $this->store);
        config()->set('beacon.requests.collection.enabled', true);
    }
}
