<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetInvestigationContextTool;
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
final class GetInvestigationContextToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttpWithoutInvestigationCapability')]
    public function the_execution_gate_rejects_a_caller_without_the_investigation_context_capability(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $response->assertJsonMissingPath('result');

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'investigation_context:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutInvestigationCapability')]
    public function the_tool_enforces_its_capability_even_if_the_execution_gate_is_bypassed(): void
    {
        $response = $this->invokeDirectly(new Principal('beacon-token:current', 'bearer-token'));

        $this->assertTrue($response->isError());
        $this->assertSame(ExecutionGate::DENIED_MESSAGE, (string) $response->content());
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithInvestigationCapability')]
    public function authorized_execution_returns_structured_context(): void
    {
        $response = $this->callTool([
            'since' => '2026-11-14T00:00:00.000+00:00',
            'until' => '2026-11-15T00:00:00.000+00:00',
            'max_results' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.structuredContent.schema_version', 1);
        $response->assertJsonPath('result.structuredContent.status', fn ($status) => is_string($status));
        $this->assertIsArray(data_get($response->json(), 'result.structuredContent.missing_evidence'));
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutInvestigationCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['requests', 'exceptions']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithInvestigationCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['investigation_context']);
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetInvestigationContextTool())->handle(
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
                'name' => 'beacon.get_investigation_context',
                'arguments' => $arguments,
            ],
        ], ['Authorization' => 'Bearer ' . self::TOKEN]);
    }
}
