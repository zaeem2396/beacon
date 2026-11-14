<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Data\RequestInspection;
use Beacon\Http\Middleware\RecordBeaconRequest;
use Beacon\Inspectors\RequestInspector;
use Beacon\Requests\DatabaseRequestStore;
use Beacon\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional request-collection checks against CommerceOps MySQL.
 *
 * Creates only beacon_requests when missing, records a probe request,
 * inspects it, then deletes the probe rows. Business tables are untouched.
 */
#[Group('commerceops')]
final class CommerceOpsRequestTest extends TestCase
{
    #[Test]
    public function commerceops_request_collection_can_be_inspected(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded.');
        }

        $host = $this->env('BEACON_COMMERCEOPS_HOST');
        $root = $this->env('BEACON_COMMERCEOPS_PATH');

        if ($host === null || $root === null || ! is_dir($root)) {
            $this->markTestSkipped('CommerceOps is not available for request inspection.');
        }

        $database = $this->env('BEACON_COMMERCEOPS_DATABASE') ?? 'commerceops';
        $username = $this->env('BEACON_COMMERCEOPS_USERNAME') ?? 'commerceops';
        $password = $this->env('BEACON_COMMERCEOPS_PASSWORD') ?? '';
        $port = $this->env('BEACON_COMMERCEOPS_PORT') ?? '33060';
        $previous = $this->application()->basePath();
        $marker = 'beacon-cmp-req-' . bin2hex(random_bytes(4));
        $createdTable = false;

        try {
            $this->application()->setBasePath($root);
            config()->set('database.default', 'commerceops');
            config()->set('database.connections.commerceops', [
                'driver' => 'mysql',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
            ]);
            config()->set('beacon.requests.collection.enabled', true);
            config()->set('beacon.requests.connection', 'commerceops');
            config()->set('beacon.security.allowed_capabilities', ['requests']);

            if (! Schema::connection('commerceops')->hasTable(DatabaseRequestStore::TABLE)) {
                $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_11_14_000001_create_beacon_requests_table.php';
                $migration->up();
                $createdTable = true;
            }

            Route::get('/beacon-request-probe', static fn () => response('ok'))->name('beacon.request.probe');

            $request = Request::create('/beacon-request-probe', 'GET', ['token' => 'query-token-not-stored'], [], [], [
                'HTTP_X_REQUEST_ID' => $marker,
                'HTTP_AUTHORIZATION' => 'authorization-header-not-stored',
                'HTTP_COOKIE' => 'session-cookie-not-stored=1',
            ]);
            $route = Route::getRoutes()->match($request);
            $request->setRouteResolver(static fn () => $route);

            $middleware = $this->application()->make(RecordBeaconRequest::class);
            $response = $middleware->handle($request, static fn () => new Response('ok', 200));
            $this->assertInstanceOf(Response::class, $response);
            $middleware->terminate($request, $response);

            $inspection = $this->application()->make(RequestInspector::class)->inspect([
                'correlation_id' => $marker,
                'limit' => 10,
            ]);
            $this->assertInstanceOf(RequestInspection::class, $inspection);
            $this->assertGreaterThan(0, count($inspection->requests));
            $encoded = (string) json_encode($inspection->toArray());
            $this->assertStringContainsString($marker, $encoded);
            $this->assertStringNotContainsString('authorization-header-not-stored', $encoded);
            $this->assertStringNotContainsString('session-cookie-not-stored', $encoded);
            $this->assertStringNotContainsString('query-token-not-stored', $encoded);
            $this->assertSame($marker, $inspection->requests[0]->requestId);
            $this->assertSame(200, $inspection->requests[0]->status);
        } finally {
            try {
                if (Schema::connection('commerceops')->hasTable(DatabaseRequestStore::TABLE)) {
                    DB::connection('commerceops')->table(DatabaseRequestStore::TABLE)->where('request_id', $marker)->delete();

                    if ($createdTable) {
                        Schema::connection('commerceops')->drop(DatabaseRequestStore::TABLE);
                    }
                }
            } catch (\Throwable) {
                // Cleanup best-effort only.
            }

            $this->application()->setBasePath($previous);
            config()->set('beacon.requests.collection.enabled', false);
            config()->set('beacon.requests.connection', null);
        }
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
