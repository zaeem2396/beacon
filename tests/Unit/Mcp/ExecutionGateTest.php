<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Mcp;

use Beacon\Contracts\Authorizer;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Mcp\ExecutionGate;
use Beacon\Security\AuthorizationDecision;
use Beacon\Security\Capability;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\Mcp\EchoTool;
use Beacon\Tests\Fixtures\Mcp\UndeclaredTool;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('mcp')]
final class ExecutionGateTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function descriptiveMethods(): iterable
    {
        foreach (['initialize', 'ping', 'tools/list', 'resources/list', 'resources/templates/list', 'prompts/list'] as $method) {
            yield $method => [$method];
        }
    }

    #[Test]
    #[DataProvider('descriptiveMethods')]
    public function descriptive_methods_need_no_capability(string $method): void
    {
        $this->gate(allow: false)->authorize($this->request($method), $this->context(), null);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function methods_outside_the_known_set_are_denied(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage(ExecutionGate::DENIED_MESSAGE);

        $this->gate(allow: true)->authorize($this->request('artisan/call', ['command' => 'migrate:fresh']), $this->context(), Principal::local());
    }

    #[Test]
    public function primitives_without_a_declared_capability_are_denied(): void
    {
        $this->expectException(JsonRpcException::class);

        $this->gate(allow: true)->authorize(
            $this->request('tools/call', ['name' => 'undeclared-tool']),
            $this->context([new UndeclaredTool()]),
            Principal::local(),
        );
    }

    #[Test]
    public function execution_without_an_identity_is_denied(): void
    {
        $this->expectException(JsonRpcException::class);

        $this->gate(allow: true)->authorize($this->request('tools/call', ['name' => 'echo-tool']), $this->context([new EchoTool()]), null);
    }

    #[Test]
    public function the_declared_capability_is_checked_for_the_caller(): void
    {
        $this->gate(allow: true)->authorize($this->request('tools/call', ['name' => 'echo-tool']), $this->context([new EchoTool()]), Principal::local());

        $this->expectException(JsonRpcException::class);

        $this->gate(allow: false)->authorize($this->request('tools/call', ['name' => 'echo-tool']), $this->context([new EchoTool()]), Principal::local());
    }

    #[Test]
    public function unknown_targets_are_left_to_the_protocol_layer(): void
    {
        $this->gate(allow: false)->authorize($this->request('tools/call', ['name' => 'missing']), $this->context([new EchoTool()]), null);

        $this->addToAssertionCount(1);
    }

    private function gate(bool $allow): ExecutionGate
    {
        return new ExecutionGate(new class ($allow) implements Authorizer {
            public function __construct(private readonly bool $allow)
            {
            }

            public function decide(Principal $principal, Capability $capability): AuthorizationDecision
            {
                return $this->allow ? AuthorizationDecision::allow() : AuthorizationDecision::deny('Denied.');
            }

            public function authorize(Principal $principal, Capability $capability): void
            {
                if (! $this->allow) {
                    throw AuthorizationException::denied($capability, $this->decide($principal, $capability));
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(string $method, array $params = []): JsonRpcRequest
    {
        return new JsonRpcRequest(1, $method, $params);
    }

    /**
     * @param list<\Laravel\Mcp\Server\Tool> $tools
     */
    private function context(array $tools = []): ServerContext
    {
        return new ServerContext(
            supportedProtocolVersions: ['2025-06-18'],
            serverCapabilities: [],
            implementation: new Implementation('test', '1.0.0'),
            instructions: '',
            maxPaginationLength: 50,
            defaultPaginationLength: 15,
            tools: $tools,
            resources: [],
            prompts: [],
        );
    }
}
