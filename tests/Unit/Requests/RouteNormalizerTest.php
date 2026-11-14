<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Requests;

use Beacon\Requests\RouteNormalizer;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteNormalizerTest extends TestCase
{
    private RouteNormalizer $normalizer;

    #[Test]
    public function it_returns_unmatched_without_a_route(): void
    {
        $request = Request::create('/unknown-path', 'GET');

        $this->assertNull($this->normalizer->name(null));
        $this->assertSame(RouteNormalizer::UNMATCHED, $this->normalizer->uri($request, null));
        $this->assertNull($this->normalizer->action(null));
        $this->assertSame('GET', $this->normalizer->method($request));
    }

    #[Test]
    public function it_normalizes_named_routes_and_actions(): void
    {
        $request = Request::create('/users/1', 'GET');
        $route = new Route(['GET'], 'users/{id}', [
            'as' => 'users.show',
            'controller' => 'UserController@show',
        ]);
        $route->bind($request);

        $this->assertSame('users.show', $this->normalizer->name($route));
        $this->assertSame('/users/{id}', $this->normalizer->uri($request, $route));
        $this->assertSame('UserController@show', $this->normalizer->action($route));
    }

    #[Test]
    public function it_marks_closures_explicitly(): void
    {
        $route = new Route(['GET'], 'probe', ['uses' => fn (): string => 'ok']);

        $this->assertSame('Closure', $this->normalizer->action($route));
    }

    #[Test]
    public function it_rejects_unsafe_route_names(): void
    {
        $route = new Route(['GET'], 'probe', ['as' => 'bad name!', 'uses' => 'ProbeController@show']);

        $this->assertNull($this->normalizer->name($route));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new RouteNormalizer();
    }
}
