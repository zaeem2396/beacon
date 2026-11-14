<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Requests;

use Beacon\Requests\DatabaseRequestStore;
use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\RequestStore;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * SQLite round-trip for DatabaseRequestStore. Skipped without pdo_sqlite.
 */
final class DatabaseRequestStoreTest extends TestCase
{
    #[Test]
    public function it_writes_searches_and_prunes_sqlite_rows(): void
    {
        $store = $this->application()->make(RequestStore::class);
        $this->assertInstanceOf(DatabaseRequestStore::class, $store);
        $this->assertTrue($store->available());

        $store->write(new RequestObservation(
            requestId: 'request-id-db-0001',
            correlationId: 'request-id-db-0001',
            method: 'GET',
            routeName: 'probe.show',
            routeUri: '/probe',
            action: 'Closure',
            status: 200,
            startedAt: '2020-01-01T00:00:00.000+00:00',
            finishedAt: '2020-01-01T00:00:00.010+00:00',
            durationMs: 10,
            environment: 'testing',
            hadException: false,
            exceptionId: null,
            exceptionClass: null,
        ));

        $search = $store->search(RequestQuery::from(['limit' => 10]));
        $this->assertSame(1, $search['total']);
        $this->assertSame('request-id-db-0001', $search['records'][0]->requestId);
        $this->assertSame(1, DB::table(DatabaseRequestStore::TABLE)->count());

        $deleted = $store->prune(24);
        $this->assertSame(1, $deleted);
        $this->assertSame(0, DB::table(DatabaseRequestStore::TABLE)->count());
    }
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not loaded.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'beacon.requests.connection' => null,
        ]);

        $this->loadMigrationsFrom(dirname(__DIR__, 3) . '/database/migrations');
    }
}
