<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Contracts\Redactor;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Last line of defence for every message the MCP server sends.
 *
 * Inspector output is already redacted by {@see \Beacon\Core\Payload}. This
 * also covers what bypasses payloads, such as error messages, which
 * laravel/mcp returns verbatim while "app.debug" is enabled. Only values are
 * inspected: protocol keys are left alone so the message stays valid.
 */
final class ResponseSanitizer
{
    public function __construct(
        private readonly Redactor $redactor,
    ) {
    }

    public function sanitize(JsonRpcResponse $response): JsonRpcResponse
    {
        $response->content = array_map($this->value(...), $response->content);

        return $response;
    }

    private function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactor->isSensitiveValue($value) ? Redactor::REPLACEMENT : $value;
        }

        if (is_array($value)) {
            return array_map($this->value(...), $value);
        }

        if ($value instanceof \stdClass) {
            $object = new \stdClass();

            foreach (get_object_vars($value) as $key => $item) {
                $object->{$key} = $this->value($item);
            }

            return $object;
        }

        if (is_object($value)) {
            // Serialize other objects the way json_encode would, keeping "{}" as an object.
            return $this->value(json_decode((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)));
        }

        return $value;
    }
}
