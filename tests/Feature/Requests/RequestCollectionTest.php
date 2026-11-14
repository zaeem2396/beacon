<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Requests;

use Beacon\Http\Middleware\RecordBeaconRequest;
use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\RequestStore;
use Beacon\Requests\RouteNormalizer;
use Beacon\Tests\Fixtures\ArrayRequestStore;
use Beacon\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

final class RequestCollectionTest extends TestCase
{
    private ArrayRequestStore $store;

    #[Test]
    public function disabled_collection_writes_nothing(): void
    {
        config()->set('beacon.requests.collection.enabled', false);

        $this->record(Request::create('/probe', 'GET'), new Response('ok', 200));

        $this->assertSame(0, $this->store->search(RequestQuery::from([]))['total']);
    }

    #[Test]
    public function enabled_collection_stores_sanitized_observations(): void
    {
        config()->set('beacon.requests.collection.enabled', true);

        $request = Request::create(
            '/checkout?token=payment-token-not-in-storage',
            'POST',
            ['card' => 'payment-token-not-in-storage'],
            ['session' => 'cookie-secret-not-stored'],
            [],
            [
                'HTTP_AUTHORIZATION' => 'authorization-header-not-stored',
                'HTTP_COOKIE' => 'session=cookie-secret-not-stored',
                'HTTP_X_REQUEST_ID' => 'probe-request-id-01',
            ],
            'body=payment-token-not-in-storage',
        );

        Route::post('/checkout', static fn () => response('ok'))->name('checkout.store');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);

        $this->record($request, new Response('ok', 201));

        $search = $this->store->search(RequestQuery::from([]));
        $this->assertSame(1, $search['total']);
        $observation = $search['records'][0];
        $this->assertInstanceOf(RequestObservation::class, $observation);
        $encoded = (string) json_encode(get_object_vars($observation));

        $this->assertStringNotContainsString('payment-token-not-in-storage', $encoded);
        $this->assertStringNotContainsString('authorization-header-not-stored', $encoded);
        $this->assertStringNotContainsString('cookie-secret-not-stored', $encoded);
        $this->assertSame('probe-request-id-01', $observation->requestId);
        $this->assertSame('POST', $observation->method);
        $this->assertSame('checkout.store', $observation->routeName);
        $this->assertSame(201, $observation->status);
        $this->assertFalse($observation->hadException);
    }

    #[Test]
    public function exceptions_mark_had_exception_and_exception_id(): void
    {
        config()->set('beacon.requests.collection.enabled', true);

        $request = Request::create('/boom', 'GET');
        Route::get('/boom', static fn () => throw new \RuntimeException('boom'));
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);

        $middleware = $this->application()->make(RecordBeaconRequest::class);

        try {
            $middleware->handle($request, static function (): never {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception.');
        } catch (\RuntimeException) {
            $middleware->terminate($request, new Response('error', 500));
        }

        $observation = $this->store->search(RequestQuery::from([]))['records'][0] ?? null;
        $this->assertInstanceOf(RequestObservation::class, $observation);
        $this->assertTrue($observation->hadException);
        $this->assertNotNull($observation->exceptionId);
        $this->assertSame(\RuntimeException::class, $observation->exceptionClass);
        $this->assertSame(500, $observation->status);
    }

    #[Test]
    public function unmatched_routes_use_the_unmatched_uri(): void
    {
        config()->set('beacon.requests.collection.enabled', true);

        $request = Request::create('/no-matching-route', 'GET');
        $this->record($request, new Response('missing', 404));

        $observation = $this->store->search(RequestQuery::from([]))['records'][0] ?? null;
        $this->assertInstanceOf(RequestObservation::class, $observation);
        $this->assertSame(RouteNormalizer::UNMATCHED, $observation->routeUri);
        $this->assertNull($observation->routeName);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ArrayRequestStore();
        $this->application()->instance(RequestStore::class, $this->store);

        config([
            'beacon.requests.collection.enabled' => false,
        ]);
    }

    private function record(Request $request, Response $response): void
    {
        $middleware = $this->application()->make(RecordBeaconRequest::class);
        $middleware->handle($request, static fn () => $response);
        $middleware->terminate($request, $response);
    }
}
