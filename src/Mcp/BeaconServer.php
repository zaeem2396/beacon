<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Security\Principal;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

final class BeaconServer extends Server
{
    protected string $name = 'Laravel Beacon';

    protected string $version = '0.1.0-dev';

    protected string $instructions = <<<'MARKDOWN'
        Laravel Beacon provides read-only, structured and redacted information about
        this Laravel application for investigating production problems.
        Beacon never performs mutating or destructive actions and never returns secrets.
        MARKDOWN;

    private readonly ?Principal $principal;

    public function __construct(
        Transport $transport,
        private readonly ServerRegistration $registration,
        McpRegistry $registry,
        CallerResolver $callers,
        private readonly ExecutionGate $gate,
        private readonly ResponseSanitizer $sanitizer,
    ) {
        parent::__construct($transport);

        $this->principal = $callers->resolve($transport);
        $this->tools = $registry->tools();
        $this->resources = $registry->resources();
        $this->prompts = $registry->prompts();
    }

    /**
     * @throws McpUnavailableException
     */
    public function start(): void
    {
        $this->registration->ensureAvailable();

        parent::start();
    }

    /**
     * Authorizes the request, then exposes the caller to the primitive (as
     * {@see Principal}) for the duration of the request, streamed or not.
     */
    protected function handleMessage(JsonRpcRequest $request, ServerContext $context): void
    {
        $this->gate->authorize($request, $context, $this->principal);

        $container = Container::getInstance();

        if ($this->principal !== null) {
            $container->instance(Principal::class, $this->principal);
        }

        try {
            parent::handleMessage($request, $context);
        } finally {
            $container->forgetInstance(Principal::class);
        }
    }

    protected function send(JsonRpcResponse $response, ServerContext $context, ?JsonRpcRequest $request = null): void
    {
        parent::send($this->sanitizer->sanitize($response), $context, $request);
    }
}
