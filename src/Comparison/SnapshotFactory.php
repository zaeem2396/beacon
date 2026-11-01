<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Core\Version;
use Beacon\Data\SchemaConnection;
use Beacon\Data\SchemaInspection;
use Beacon\Data\SchemaSnapshotInspection;
use Beacon\Database\ConnectionStatus;
use Beacon\Health\Clock;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Schema\SchemaQuery;
use Illuminate\Contracts\Foundation\Application;

/**
 * Builds a snapshot by paging the existing schema inspector.
 *
 * The SQL stays in schema inspection. This factory does not read table rows.
 */
final class SnapshotFactory
{
    public function __construct(
        private readonly SchemaInspector $schemas,
        private readonly Clock $clock,
        private readonly Application $application,
    ) {
    }

    public function capture(?string $connection): SchemaSnapshotInspection
    {
        $objects = [];
        $page = 1;
        $inspection = null;
        $complete = true;
        $warnings = [];

        while ($page <= SchemaQuery::MAX_PAGE) {
            $read = $this->schemas->inspect([
                'connection' => $connection,
                'include_views' => true,
                'include_columns' => true,
                'include_indexes' => true,
                'include_foreign_keys' => true,
                'page' => $page,
                'limit' => SchemaQuery::MAX_LIMIT,
            ]);

            if (! $read instanceof SchemaInspection) {
                throw new \RuntimeException('Schema inspection returned an unexpected report.');
            }

            $inspection = $read;

            if ($read->connection->status !== ConnectionStatus::Connected) {
                return $this->unavailable($read);
            }

            foreach ($read->warnings as $warning) {
                if (! in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }

                if ($warning === 'Schema discovery stopped at the object limit.') {
                    $complete = false;
                }
            }

            if ($read->objects === []) {
                break;
            }

            foreach ($read->objects as $object) {
                $objects[] = SnapshotObject::fromSchema($object);

                if (count($objects) >= SnapshotDocument::MAX_OBJECTS) {
                    $complete = false;

                    break 2;
                }
            }

            if (count($objects) >= $read->pagination->total || $page >= $read->pagination->pages) {
                break;
            }

            $page++;
        }

        if (count($objects) < $inspection->pagination->total) {
            $complete = false;
        }

        usort(
            $objects,
            static fn (SnapshotObject $left, SnapshotObject $right): int => [$left->type->value, $left->name] <=> [$right->type->value, $right->name],
        );

        return new SchemaSnapshotInspection(
            $inspection->connection,
            $this->document($inspection->connection, $objects, $complete),
            array_slice($warnings, 0, 8),
        );
    }

    /**
     * @param list<SnapshotObject> $objects
     */
    private function document(SchemaConnection $connection, array $objects, bool $complete): SnapshotDocument
    {
        $driver = in_array($connection->driver, ['mysql', 'mariadb'], true) ? $connection->driver : null;
        $laravel = $this->application->version();

        return new SnapshotDocument(new SnapshotMetadata(
            $this->version(Version::current()),
            $this->version(is_string($laravel) ? $laravel : 'unknown'),
            $driver,
            $connection->version,
            $connection->database,
            'live',
            SnapshotDocument::identifier($objects),
            $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\+00:00'),
            $complete,
            true,
            true,
            true,
            true,
        ), $objects);
    }

    private function unavailable(SchemaInspection $inspection): SchemaSnapshotInspection
    {
        $createdAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\+00:00');
        $laravel = $this->application->version();

        return new SchemaSnapshotInspection(
            $inspection->connection,
            new SnapshotDocument(new SnapshotMetadata(
                $this->version(Version::current()),
                $this->version(is_string($laravel) ? $laravel : 'unknown'),
                in_array($inspection->connection->driver, ['mysql', 'mariadb'], true) ? $inspection->connection->driver : null,
                null,
                null,
                'live',
                SnapshotDocument::identifier([]),
                $createdAt,
                false,
                false,
                false,
                false,
                false,
            ), []),
            [$inspection->summary->summary],
        );
    }

    private function version(string $value): string
    {
        return ComparisonText::isVersion($value) ? $value : 'unknown';
    }
}
