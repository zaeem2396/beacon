<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetRecentExceptionsTool;
use Beacon\Security\Principal;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response as McpResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

#[Group('mcp')]
final class GetRecentExceptionsToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttpWithoutExceptionsCapability')]
    public function the_execution_gate_rejects_a_caller_without_the_exceptions_capability(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $response->assertJsonMissingPath('result');

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'exceptions:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutExceptionsCapability')]
    public function the_tool_enforces_its_capability_even_if_the_execution_gate_is_bypassed(): void
    {
        $response = $this->invokeDirectly(new Principal('beacon-token:current', 'bearer-token'));

        $this->assertTrue($response->isError());
        $this->assertSame(ExecutionGate::DENIED_MESSAGE, (string) $response->content());
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutExceptionsCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['application']);
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetRecentExceptionsTool())->handle(
            $this->application()->make(Beacon::class),
            $principal,
            new Request([]),
        );
        $this->assertInstanceOf(McpResponse::class, $response);

        return $response;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return TestResponse<Response>
     */
    private function callTool(array $arguments = []): TestResponse
    {
        return $this->postJson(self::URL, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => (object) [
                'name' => 'beacon.get_recent_exceptions',
                'arguments' => $arguments,
            ],
        ], ['Authorization' => 'Bearer ' . self::TOKEN]);
    }
}
