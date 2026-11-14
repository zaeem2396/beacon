<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Allowlisted non-secret driver and connection names.
 *
 * These are configured values, not proof that a backend is reachable.
 */
final readonly class RuntimeDrivers extends DataObject
{
    public function __construct(
        public ?string $cache,
        public ?string $session,
        public ?string $queue,
        public ?string $filesystem,
        public ?string $loggingDefault,
        public ?string $loggingChannel,
        public ?string $databaseConnection,
        public ?string $databaseDriver,
    ) {
    }
}
