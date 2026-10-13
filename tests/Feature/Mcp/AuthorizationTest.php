<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Events\AuthorizationEvaluated;
use Beacon\Mcp\BeaconServer;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\McpRegistry;
use Beacon\Security\AccessMode;
use Beacon\Security\Capability;
use Beacon\Security\CapabilityRegistry;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\Mcp\EchoPrompt;
use Beacon\Tests\Fixtures\Mcp\EchoResource;
use Beacon\Tests\Fixtures\Mcp\EchoTool;
use Beacon\Tests\Fixtures\Mcp\FailingTool;
use Beacon\Tests\Fixtures\Mcp\GuardedTool;
use Beacon\Tests\Fixtures\Mcp\RecordingTransport;
use Beacon\Tests\Fixtures\Mcp\UndeclaredTool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capability authorization through the real MCP HTTP request lifecycle.
 */
#[Group('mcp')]
final class AuthorizationTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_allowed_capability_runs_the_tool_with_the_authenticated_principal(): void
    {
        $this->registry()->tool(EchoTool::class);

        $this->callTool('echo-tool')->assertOk()->assertJsonPath('result.content.0.text', 'echo');

        $this->assertSame(1, EchoTool::$calls);
        $this->assertInstanceOf(Principal::class, EchoTool::$caller);
        $this->assertSame('beacon-token:current', EchoTool::$caller->id);
        $this->assertFalse($this->application()->bound(Principal::class), 'The principal outlived the request.');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithDefaultCapabilities')]
    public function nothing_can_be_executed_with_the_default_configuration(): void
    {
        $this->registry()->tool(EchoTool::class);

        $this->assertDenied($this->callTool('echo-tool'));
        $this->assertSame(0, EchoTool::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithDefaultCapabilities')]
    public function denied_tools_remain_listed_but_cannot_run(): void
    {
        $this->registry()->tool(EchoTool::class);

        $this->assertContains('echo-tool', array_column((array) $this->rpc('tools/list')->assertOk()->json('result.tools'), 'name'));
        $this->assertDenied($this->callTool('echo-tool'));
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function capabilities_that_are_not_allowed_are_denied(): void
    {
        $this->registry()->tool(GuardedTool::class);
        GuardedTool::$capabilityName = 'routes';

        $this->assertDenied($this->callTool('guarded-tool'));
        $this->assertSame(0, GuardedTool::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unknown_capabilities_are_denied_even_with_a_wildcard(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['*', 'internal']);
        $this->registry()->tool(GuardedTool::class);

        $this->assertDenied($this->callTool('guarded-tool'));
        $this->assertSame(0, GuardedTool::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function explicitly_registered_capabilities_can_be_allowed(): void
    {
        $this->application()->make(CapabilityRegistry::class)->register(Capability::read('internal'), 'Test-only capability.');
        $this->registry()->tool(GuardedTool::class);

        $this->assertDenied($this->callTool('guarded-tool'));

        config()->set('beacon.security.allowed_capabilities', ['application', 'internal']);

        $this->callTool('guarded-tool')->assertOk()->assertJsonPath('result.content.0.text', 'guarded');
        $this->assertSame(1, GuardedTool::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function mutating_capabilities_are_always_denied(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['*', 'queue']);
        $this->registry()->tool(GuardedTool::class);
        GuardedTool::$capabilityName = 'queue';
        GuardedTool::$mode = AccessMode::Write;

        $this->assertDenied($this->callTool('guarded-tool'));
        $this->assertSame(0, GuardedTool::$calls);

        $this->expectException(\InvalidArgumentException::class);
        $this->application()->make(CapabilityRegistry::class)->register(new Capability('queue', AccessMode::Write), 'Retry jobs.');
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function tools_must_declare_a_capability_to_be_registered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RequiresCapability');

        $this->registry()->tool(UndeclaredTool::class);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function resources_and_prompts_are_authorized_at_execution(): void
    {
        $this->registry()->resource(EchoResource::class);
        $this->registry()->prompt(EchoPrompt::class);

        $this->assertDenied($this->rpc('resources/read', ['uri' => 'beacon://tests/echo']));
        $this->assertDenied($this->rpc('prompts/get', ['name' => 'echo-prompt']));
        $this->assertSame(0, EchoResource::$calls);
        $this->assertSame(0, EchoPrompt::$calls);

        config()->set('beacon.security.allowed_capabilities', ['routes', 'exceptions']);

        $this->rpc('resources/read', ['uri' => 'beacon://tests/echo'])->assertOk()->assertJsonPath('result.contents.0.text', 'resource');
        $this->rpc('prompts/get', ['name' => 'echo-prompt'])->assertOk();
        $this->assertSame(1, EchoResource::$calls);
        $this->assertSame(1, EchoPrompt::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function every_denial_looks_the_same_and_reveals_nothing(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['*', 'internal', 'queue']);
        $this->registry()->tool(GuardedTool::class);

        $bodies = [];

        foreach ([['internal', AccessMode::Read], ['queue', AccessMode::Write]] as [$name, $mode]) {
            GuardedTool::$capabilityName = $name;
            GuardedTool::$mode = $mode;
            $bodies[] = $this->callTool('guarded-tool')->getContent();
        }

        config()->set('beacon.security.allowed_capabilities', []);
        GuardedTool::reset();
        GuardedTool::$capabilityName = 'routes';
        $bodies[] = $this->callTool('guarded-tool')->getContent();

        $this->assertCount(1, array_unique($bodies));

        foreach (['internal', 'queue', 'routes', 'registered', 'allowed_capabilities', 'read-only', self::TOKEN] as $detail) {
            $this->assertStringNotContainsString($detail, (string) $bodies[0]);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function requests_without_an_established_identity_cannot_execute_anything(): void
    {
        $this->registry()->tool(EchoTool::class);

        $transport = new RecordingTransport();
        $this->application()->make(BeaconServer::class, ['transport' => $transport])->start();

        $this->assertArrayHasKey('result', $transport->request('ping'));
        $this->assertArrayHasKey('result', $transport->request('tools/list'));

        $response = $transport->request('tools/call', ['name' => 'echo-tool', 'arguments' => (object) []]);

        $this->assertSame(ExecutionGate::PERMISSION_DENIED, data_get($response, 'error.code'));
        $this->assertSame(0, EchoTool::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function http_requests_that_bypassed_authentication_have_no_identity(): void
    {
        $this->registry()->tool(EchoTool::class);

        $request = Request::create(self::URL, 'POST', content: (string) json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo-tool', 'arguments' => (object) []],
        ]));
        $request->headers->set('Authorization', 'Bearer ' . self::TOKEN);
        $this->application()->instance('request', $request);

        $transport = new HttpTransport($request);
        $this->application()->make(BeaconServer::class, ['transport' => $transport])->start();

        $this->assertSame(ExecutionGate::PERMISSION_DENIED, data_get(json_decode((string) $transport->run()->getContent(), true), 'error.code'));
        $this->assertSame(0, EchoTool::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function every_decision_is_dispatched_as_an_audit_event_without_credentials(): void
    {
        Event::fake([AuthorizationEvaluated::class]);
        $this->registry()->tool(EchoTool::class);
        $this->registry()->tool(GuardedTool::class);

        $this->callTool('echo-tool')->assertOk();
        $this->assertDenied($this->callTool('guarded-tool'));

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => $event->allowed
            && $event->principal === 'beacon-token:current'
            && $event->capability === 'application:read');
        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'internal:read');
        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! str_contains((string) json_encode($event), self::TOKEN));
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function secrets_in_tool_errors_are_redacted_even_in_debug_mode(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['database']);
        $this->registry()->tool(FailingTool::class);

        foreach ([true, false] as $debug) {
            config()->set('app.debug', $debug);

            $content = (string) $this->callTool('failing-tool')->getContent();

            foreach (FailingTool::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, $content);
            }

            $this->assertStringNotContainsString('db.internal', $content);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        EchoTool::$calls = 0;
        EchoTool::$caller = null;
        EchoResource::$calls = 0;
        EchoPrompt::$calls = 0;
        GuardedTool::reset();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithDefaultCapabilities($app): void
    {
        $this->enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', []);
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function assertDenied(TestResponse $response): void
    {
        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $response->assertJsonMissingPath('result');
    }

    /**
     * @return TestResponse<Response>
     */
    private function callTool(string $name): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) []]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return TestResponse<Response>
     */
    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson(self::URL, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ], ['Authorization' => 'Bearer ' . self::TOKEN]);
    }

    private function registry(): McpRegistry
    {
        return $this->application()->make(McpRegistry::class);
    }
}
