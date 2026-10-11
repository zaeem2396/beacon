<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Mcp;

use Beacon\Mcp\CallerResolver;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\Mcp\RecordingTransport;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Laravel\Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('mcp')]
final class CallerResolverTest extends TestCase
{
    #[Test]
    public function stdio_callers_are_the_local_process(): void
    {
        $this->assertEquals(Principal::local(), (new CallerResolver(new Container()))->resolve(new StdioTransport()));
    }

    #[Test]
    public function http_callers_are_only_trusted_after_beacon_authenticated_them(): void
    {
        $container = new Container();
        $request = Request::create('https://localhost/beacon/mcp', 'POST');
        $request->headers->set('X-Beacon-Principal', 'admin');
        $container->instance('request', $request);
        $resolver = new CallerResolver($container);

        $this->assertNull($resolver->resolve(new HttpTransport($request)));

        $request->attributes->set(Principal::class, 'admin');
        $this->assertNull($resolver->resolve(new HttpTransport($request)));

        $principal = new Principal('beacon-token:current', 'bearer-token');
        $request->attributes->set(Principal::class, $principal);
        $this->assertSame($principal, $resolver->resolve(new HttpTransport($request)));
    }

    #[Test]
    public function other_transports_have_no_identity(): void
    {
        $this->assertNull((new CallerResolver(new Container()))->resolve(new RecordingTransport()));
    }
}
