<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Data\DataObject;
use Beacon\Database\DatabaseVersion;
use Beacon\Health\SafeName;

/**
 * Facts about how a snapshot was captured.
 *
 * These fields are not structural. Comparison ignores them except the driver
 * label and the completeness flags.
 */
final readonly class SnapshotMetadata extends DataObject
{
    public function __construct(
        public string $beaconVersion,
        public string $laravelVersion,
        public ?string $driver,
        public ?string $serverVersion,
        public ?string $database,
        public string $source,
        public string $identifier,
        public string $createdAt,
        public bool $complete,
        public bool $columnsIncluded,
        public bool $indexesIncluded,
        public bool $referencesIncluded,
        public bool $viewsIncluded,
    ) {
        if (! ComparisonText::isVersion($beaconVersion) || ! ComparisonText::isVersion($laravelVersion)) {
            throw new \InvalidArgumentException('Snapshot versions must be plain version text.');
        }

        if ($driver !== null && ! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \InvalidArgumentException('Snapshot driver must be mysql or mariadb.');
        }

        if ($serverVersion !== null && DatabaseVersion::sanitize($serverVersion) !== $serverVersion) {
            throw new \InvalidArgumentException('Snapshot server version must be a short server version.');
        }

        if ($database !== null && ! SafeName::isIdentifier($database)) {
            throw new \InvalidArgumentException('Snapshot database name must be a plain identifier.');
        }

        if (! in_array($source, ['live', 'snapshot'], true)) {
            throw new \InvalidArgumentException('Snapshot source must be live or snapshot.');
        }

        if (preg_match('/^[a-f0-9]{16}$/', $identifier) !== 1) {
            throw new \InvalidArgumentException('Snapshot identifier must be 16 hexadecimal characters.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $createdAt) !== 1) {
            throw new \InvalidArgumentException('Snapshot time must be a UTC timestamp.');
        }
    }
}
