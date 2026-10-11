<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Contracts\RequiresCapability;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

/**
 * Tools, resources and prompts exposed by the Beacon MCP server.
 *
 * Nothing is exposed unless it is explicitly registered here, and every
 * primitive must implement {@see RequiresCapability}. Registering a primitive
 * does not grant its capability. Beacon modules register their primitives
 * from a service provider:
 *
 *     $this->app->make(McpRegistry::class)->tool(GetRoutesTool::class);
 */
final class McpRegistry
{
    /**
     * @var list<class-string<Tool>>
     */
    private array $tools = [];

    /**
     * @var list<class-string<Resource>>
     */
    private array $resources = [];

    /**
     * @var list<class-string<Prompt>>
     */
    private array $prompts = [];

    /**
     * @param class-string<Tool> $tool
     */
    public function tool(string $tool): void
    {
        $this->assertSubclass($tool, Tool::class);

        if (! in_array($tool, $this->tools, true)) {
            $this->tools[] = $tool;
        }
    }

    /**
     * @param class-string<Resource> $resource
     */
    public function resource(string $resource): void
    {
        $this->assertSubclass($resource, Resource::class);

        if (! in_array($resource, $this->resources, true)) {
            $this->resources[] = $resource;
        }
    }

    /**
     * @param class-string<Prompt> $prompt
     */
    public function prompt(string $prompt): void
    {
        $this->assertSubclass($prompt, Prompt::class);

        if (! in_array($prompt, $this->prompts, true)) {
            $this->prompts[] = $prompt;
        }
    }

    /**
     * @return list<class-string<Tool>>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * @return list<class-string<Resource>>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * @return list<class-string<Prompt>>
     */
    public function prompts(): array
    {
        return $this->prompts;
    }

    private function assertSubclass(string $class, string $parent): void
    {
        if (! is_subclass_of($class, $parent)) {
            throw new \InvalidArgumentException("[{$class}] must extend {$parent}.");
        }

        if (! is_subclass_of($class, RequiresCapability::class)) {
            throw new \InvalidArgumentException("[{$class}] must implement " . RequiresCapability::class . ' to declare its capability.');
        }
    }
}
