<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Queue configuration and safely observable backend state.
 */
final readonly class QueueInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<QueueConnectionInfo> $connections
     * @param list<string> $warnings
     */
    public function __construct(
        public ?string $defaultConnection,
        public array $connections,
        public ?int $failedJobCount,
        public string $workerEvidence,
        public string $summary,
        public array $warnings,
    ) {
        if (! Prose::isSafe($summary) || ! Prose::isSafe($workerEvidence)) {
            throw new \InvalidArgumentException('Queue prose must be safe.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Queue warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
