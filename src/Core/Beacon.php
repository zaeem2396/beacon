<?php

declare(strict_types=1);

namespace Beacon\Core;

use Beacon\Contracts\Authorizer;
use Beacon\Contracts\Redactor;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;

/**
 * Entry point that consumers (MCP, CLI, ...) use to obtain Beacon data.
 *
 * Every inspection is authorized against the inspector's capability and its
 * result is returned as a redacted {@see Payload}.
 */
final class Beacon
{
    public function __construct(
        private readonly InspectorRegistry $inspectors,
        private readonly Authorizer $authorizer,
        private readonly Redactor $redactor,
    ) {
    }

    public function inspectors(): InspectorRegistry
    {
        return $this->inspectors;
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @throws InspectorNotFoundException
     * @throws AuthorizationException
     */
    public function inspect(string $name, array $parameters = []): Payload
    {
        $inspector = $this->inspectors->get($name);

        $this->authorizer->authorize($inspector->capability());

        return Payload::fromData($inspector->inspect($parameters), $this->redactor);
    }
}
