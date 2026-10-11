<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures\Mcp;

use Laravel\Mcp\Server\Contracts\Transport;

/**
 * In-memory transport that records every message the server sends.
 */
final class RecordingTransport implements Transport
{
    /**
     * @var list<string>
     */
    public array $sent = [];

    private ?\Closure $handler = null;

    public function onReceive(\Closure $handler): void
    {
        $this->handler = $handler;
    }

    public function run(): null
    {
        return null;
    }

    public function send(string $message): void
    {
        $this->sent[] = $message;
    }

    public function stream(\Closure $stream): void
    {
        $stream();
    }

    /**
     * Deliver a JSON-RPC request to the server and return its decoded response.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = [], int $id = 1): array
    {
        if ($this->handler === null) {
            throw new \LogicException('The server has not been started.');
        }

        ($this->handler)(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], JSON_THROW_ON_ERROR));

        $response = json_decode((string) end($this->sent), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($response)) {
            throw new \UnexpectedValueException('The server sent a non-object response.');
        }

        /** @var array<string, mixed> $response */
        return $response;
    }
}
