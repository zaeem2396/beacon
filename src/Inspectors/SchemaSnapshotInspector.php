<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Comparison\SnapshotFactory;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\SchemaSnapshotInspection;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidSchemaQueryException;
use Beacon\Health\SafeName;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Captures a schema snapshot through schema inspection.
 *
 * The schema capability is required in addition to schema_snapshot. A denied
 * caller does not reach the schema reader.
 *
 * @implements Inspector<SchemaSnapshotInspection>
 */
final class SchemaSnapshotInspector implements Inspector
{
    public const NAME = 'schema_snapshot';

    public function __construct(
        private readonly SnapshotFactory $factory,
        private readonly BeaconConfig $config,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::SchemaSnapshot->capability();
    }

    public function inspect(array $parameters = []): SchemaSnapshotInspection
    {
        $connection = $this->connection($parameters);

        if (! $this->schemaAllowed()) {
            throw new AuthorizationException('The schema capability is not allowed.');
        }

        return $this->factory->capture($connection);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function connection(array $parameters): ?string
    {
        foreach ($parameters as $key => $value) {
            if ($key !== 'connection') {
                throw InvalidSchemaQueryException::unknownParameter();
            }

            if ($value !== null && ! is_string($value)) {
                throw InvalidSchemaQueryException::type('connection');
            }
        }

        if (! array_key_exists('connection', $parameters) || $parameters['connection'] === null) {
            return null;
        }

        $name = $parameters['connection'];

        if (! SafeName::isIdentifier($name)) {
            throw InvalidSchemaQueryException::characters('connection');
        }

        return $name;
    }

    private function schemaAllowed(): bool
    {
        $allowed = $this->config->allowedCapabilities();

        return in_array('*', $allowed, true) || in_array(InspectionCapability::Schema->value, $allowed, true);
    }
}
