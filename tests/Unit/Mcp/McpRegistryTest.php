<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Mcp;

use Beacon\Mcp\McpRegistry;
use Beacon\Tests\Fixtures\ApplicationInspector;
use Beacon\Tests\Fixtures\Mcp\EchoTool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('mcp')]
final class McpRegistryTest extends TestCase
{
    #[Test]
    public function it_starts_empty(): void
    {
        $registry = new McpRegistry();

        $this->assertSame([], $registry->tools());
        $this->assertSame([], $registry->resources());
        $this->assertSame([], $registry->prompts());
    }

    #[Test]
    public function it_registers_tools_once(): void
    {
        $registry = new McpRegistry();

        $registry->tool(EchoTool::class);
        $registry->tool(EchoTool::class);

        $this->assertSame([EchoTool::class], $registry->tools());
    }

    #[Test]
    public function it_rejects_classes_that_are_not_mcp_tools(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type
        (new McpRegistry())->tool(ApplicationInspector::class);
    }

    #[Test]
    public function it_rejects_tools_registered_as_resources(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type
        (new McpRegistry())->resource(EchoTool::class);
    }

    #[Test]
    public function it_rejects_tools_registered_as_prompts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type
        (new McpRegistry())->prompt(EchoTool::class);
    }
}
