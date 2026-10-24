<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Safe environment metadata plus the deterministic checks run against it.
 *
 * This is not an application identity report. Name, URL, versions and
 * maintenance mode stay on {@see ApplicationInfo}.
 */
final readonly class EnvironmentInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public EnvironmentFacts $metadata,
        public array $findings,
    ) {
        foreach ($findings as $finding) {
            if (! $finding instanceof Finding) {
                throw new \InvalidArgumentException('Environment inspection findings must be findings.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
