<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\QueueInspection;
use Beacon\Inspectors\QueueInspector;
use Beacon\QueueIntelligence\QueueStatus;
use Beacon\Security\Principal;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class QueueInspectorTest extends TestCase
{
    #[Test]
    public function it_reports_configured_queue_connections(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['queue']);
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync', ['driver' => 'sync']);
        config()->set('queue.failed.driver', 'null');

        $inspection = $this->application()->make(QueueInspector::class)->inspect();
        $this->assertInstanceOf(QueueInspection::class, $inspection);
        $this->assertSame('sync', $inspection->defaultConnection);
        $this->assertNotSame([], $inspection->connections);

        $sync = null;

        foreach ($inspection->connections as $connection) {
            if ($connection->name === 'sync') {
                $sync = $connection;
            }
        }

        $this->assertNotNull($sync);
        $this->assertSame('sync', $sync->driver);
        $this->assertSame(QueueStatus::Ready, $sync->backendStatus);
        $this->assertSame(0, $inspection->failedJobCount);
        $this->assertStringContainsString('Queue configuration was read', $inspection->summary);

        $payload = $this->application()->make(Beacon::class)->inspect(Principal::local(), QueueInspector::NAME)->toArray();
        $this->assertSame('sync', $payload['default_connection'] ?? null);
    }

    #[Test]
    public function it_rejects_arguments(): void
    {
        $this->expectException(\Beacon\Exceptions\InvalidRuntimeQueryException::class);
        $this->application()->make(QueueInspector::class)->inspect(['name' => 'sync']);
    }
}
