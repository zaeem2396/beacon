<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Data\DataObject;
use Beacon\Schema\ObjectType;

/**
 * A versioned schema snapshot.
 *
 * format_version is the document contract. Structural comparison uses objects
 * and the completeness flags, not the surrounding metadata.
 */
final readonly class SnapshotDocument extends DataObject
{
    public const FORMAT_VERSION = 1;

    public const MAX_OBJECTS = 500;

    public int $formatVersion;

    /**
     * @param list<SnapshotObject> $objects
     */
    public function __construct(
        public SnapshotMetadata $metadata,
        public array $objects,
    ) {
        if (count($objects) > self::MAX_OBJECTS) {
            throw new \InvalidArgumentException('A snapshot has too many objects.');
        }

        $names = [];

        foreach ($objects as $object) {
            if (! $object instanceof SnapshotObject || isset($names[$object->type->value . '.' . $object->name])) {
                throw new \InvalidArgumentException('Snapshot objects must be unique tables and views.');
            }

            if ($object->type === ObjectType::View && ! $metadata->viewsIncluded) {
                throw new \InvalidArgumentException('A snapshot without views cannot contain a view.');
            }

            $names[$object->type->value . '.' . $object->name] = true;
        }

        $this->formatVersion = self::FORMAT_VERSION;
    }

    /**
     * @param list<SnapshotObject> $objects
     */
    public static function identifier(array $objects): string
    {
        $encoded = json_encode(array_map(static fn (SnapshotObject $object): array => $object->toArray(), $objects));

        return substr(hash('sha256', is_string($encoded) ? $encoded : ''), 0, 16);
    }
}
