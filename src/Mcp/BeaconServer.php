<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

final class BeaconServer extends Server
{
    protected string $name = 'Laravel Beacon';

    protected string $version = '0.1.0-dev';

    protected string $instructions = <<<'MARKDOWN'
        Laravel Beacon provides read-only, structured and redacted information about
        this Laravel application for investigating production problems.
        Beacon never performs mutating or destructive actions and never returns secrets.
        MARKDOWN;

    public function __construct(
        Transport $transport,
        private readonly ServerRegistration $registration,
        McpRegistry $registry,
    ) {
        parent::__construct($transport);

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
}
