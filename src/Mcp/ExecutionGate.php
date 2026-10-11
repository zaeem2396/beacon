<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Contracts\Authorizer;
use Beacon\Contracts\RequiresCapability;
use Beacon\Security\Principal;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\Concerns\ResolvesPrompts;
use Laravel\Mcp\Server\Methods\Concerns\ResolvesResources;
use Laravel\Mcp\Server\Primitive;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * The single place where MCP requests are authorized before anything runs.
 *
 * Protocol and listing methods only describe the server. Methods that
 * execute a primitive require an identity and the primitive's declared
 * capability. Every other method is denied. Denials are deliberately
 * indistinguishable so clients learn nothing about the reason.
 */
final class ExecutionGate
{
    use ResolvesPrompts;
    use ResolvesResources;

    public const PERMISSION_DENIED = -32001;

    public const DENIED_MESSAGE = 'Permission denied.';

    private const DESCRIPTIVE_METHODS = [
        'initialize',
        'ping',
        'server/discover',
        'subscriptions/listen',
        'tools/list',
        'resources/list',
        'resources/templates/list',
        'prompts/list',
    ];

    private const EXECUTING_METHODS = [
        'tools/call',
        'resources/read',
        'prompts/get',
        'completion/complete',
    ];

    public function __construct(
        private readonly Authorizer $authorizer,
    ) {
    }

    /**
     * @throws JsonRpcException
     */
    public function authorize(JsonRpcRequest $request, ServerContext $context, ?Principal $principal): void
    {
        if (in_array($request->method, self::DESCRIPTIVE_METHODS, true)) {
            return;
        }

        if (! in_array($request->method, self::EXECUTING_METHODS, true)) {
            throw $this->denied($request);
        }

        $primitive = $this->target($request, $context);

        // Unknown targets are rejected by laravel/mcp itself and never run.
        if ($primitive === null) {
            return;
        }

        if ($principal === null || ! $primitive instanceof RequiresCapability) {
            throw $this->denied($request);
        }

        if (! $this->authorizer->decide($principal, $primitive->capability())->allowed) {
            throw $this->denied($request);
        }
    }

    private function target(JsonRpcRequest $request, ServerContext $context): ?Primitive
    {
        try {
            return match ($request->method) {
                'tools/call' => $context->tools()->first(
                    static fn (mixed $tool): bool => $tool instanceof Tool && $tool->name() === $request->get('name'),
                ),
                'resources/read' => $this->resolveResource($this->string($request->get('uri')), $context),
                'prompts/get' => $this->resolvePrompt($this->string($request->get('name')), $context),
                'completion/complete' => $this->completionTarget($request->get('ref'), $context),
                default => null,
            };
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function completionTarget(mixed $ref, ServerContext $context): ?Primitive
    {
        if (! is_array($ref)) {
            return null;
        }

        return match ($ref['type'] ?? null) {
            'ref/prompt' => $this->resolvePrompt($this->string($ref['name'] ?? null), $context),
            'ref/resource' => $this->resolveResource($this->string($ref['uri'] ?? null), $context),
            default => null,
        };
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function denied(JsonRpcRequest $request): JsonRpcException
    {
        return new JsonRpcException(self::DENIED_MESSAGE, self::PERMISSION_DENIED, $request->id);
    }
}
