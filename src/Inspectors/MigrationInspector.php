<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\MigrationConnection;
use Beacon\Data\MigrationInspection;
use Beacon\Data\MigrationPage;
use Beacon\Data\MigrationRepositoryInfo;
use Beacon\Data\MigrationScope;
use Beacon\Data\MigrationSummary;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\InvalidMigrationQueryException;
use Beacon\Health\Clock;
use Beacon\Health\SafeName;
use Beacon\Migrations\CatalogResult;
use Beacon\Migrations\DiscoveryResult;
use Beacon\Migrations\HistoryView;
use Beacon\Migrations\MigrationCatalog;
use Beacon\Migrations\MigrationHistory;
use Beacon\Migrations\MigrationPaths;
use Beacon\Migrations\MigrationQuery;
use Beacon\Migrations\MigrationScanner;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Illuminate\Contracts\Config\Repository;

/**
 * Compares migration filenames with the migration repository.
 *
 * Files are not included or executed. The repository table is not created.
 * PostgreSQL and SQL Server are identified and not opened.
 *
 * @implements Inspector<MigrationInspection>
 */
final class MigrationInspector implements Inspector
{
    public const NAME = 'migrations';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly Repository $repository,
        private readonly MigrationPaths $paths,
        private readonly MigrationScanner $scanner,
        private readonly MigrationHistory $history,
        private readonly MigrationCatalog $catalog,
        private readonly Clock $clock,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Migrations->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        $query = MigrationQuery::from($parameters);
        $inspectedAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\+00:00');
        $timeout = $this->config->migrationsTimeout();

        if ($timeout === null) {
            return $this->empty($query, null, null, ConnectionStatus::Unknown, null, $inspectedAt, 'The migration inspection timeout must be an integer from 1 to 5 seconds.');
        }

        $default = $this->repository->get('database.default');
        $allowed = $this->allowed(is_string($default) ? $default : null);
        $name = $query->connection ?? (is_string($default) ? $default : null);

        if (! is_string($name) || ! SafeName::isIdentifier($name) || ! in_array($name, $allowed, true)) {
            $summary = is_string($name) && SafeName::isIdentifier($name)
                ? 'The requested database connection is not allowed for migration inspection.'
                : 'The database connection could not be read safely.';

            return $this->empty($query, null, null, ConnectionStatus::Unknown, null, $inspectedAt, $summary);
        }

        $definition = $this->repository->get('database.connections.' . $name);

        if (! is_array($definition)) {
            return $this->empty($query, $name, null, ConnectionStatus::Unknown, null, $inspectedAt, 'The database connection is not registered.');
        }

        $connection = [];

        foreach ($definition as $key => $value) {
            if (! is_string($key)) {
                return $this->empty($query, $name, null, ConnectionStatus::Unknown, null, $inspectedAt, 'The database connection could not be read safely.');
            }

            $connection[$key] = $value;
        }

        $driver = $connection['driver'] ?? null;
        $driver = is_string($driver) ? strtolower($driver) : null;

        if (! is_string($driver) || ! in_array($driver, MigrationConnection::DRIVERS, true)) {
            return $this->empty($query, $name, null, ConnectionStatus::Unknown, null, $inspectedAt, 'The database driver could not be read safely.');
        }

        $discovery = $this->scanner->discover(base_path(), $this->paths->directories());

        if ($query->path !== null && ! in_array($query->path, $discovery->directories, true)) {
            throw InvalidMigrationQueryException::path();
        }

        $table = $this->table();
        $history = $this->history($connection, $driver, $timeout, $table);
        $catalog = $this->catalog->classify($discovery->files, $history->batches, $history->available, $query);

        return $this->inspection($query, $name, $driver, $table, $history, $catalog, $discovery, $inspectedAt);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function history(array $connection, string $driver, int $timeout, ?string $table): HistoryView
    {
        if (! in_array($driver, MigrationConnection::OPENED_DRIVERS, true)) {
            return HistoryView::closed(ConnectionStatus::Unsupported, HistoryView::UNSUPPORTED);
        }

        if (! $this->sqliteIsReadable($connection)) {
            return HistoryView::closed(ConnectionStatus::Unknown, HistoryView::SQLITE);
        }

        if ($table === null) {
            return HistoryView::closed(ConnectionStatus::Unknown, HistoryView::TABLE);
        }

        try {
            return HistoryView::opened($this->history->read($connection, $timeout, $table));
        } catch (\Throwable) {
            return HistoryView::closed(ConnectionStatus::Unavailable, HistoryView::FAILED);
        }
    }

    private function inspection(
        MigrationQuery $query,
        string $name,
        string $driver,
        ?string $table,
        HistoryView $history,
        CatalogResult $catalog,
        DiscoveryResult $discovery,
        string $inspectedAt,
    ): MigrationInspection {
        $total = count($catalog->migrations);
        $page = array_slice($catalog->migrations, ($query->page - 1) * $query->limit, $query->limit);

        return new MigrationInspection(
            new MigrationConnection($name, $driver, $history->status),
            new MigrationRepositoryInfo($history->available, $history->empty, $table),
            new MigrationSummary(
                $catalog->fileCount,
                $catalog->executed,
                $catalog->pending,
                $catalog->missingFiles,
                $catalog->duplicateNames,
                count($catalog->batches),
                $inspectedAt,
                $this->summary($history, $query, $catalog),
            ),
            new MigrationScope($query->filtered(), $query->name, $query->status, $query->batch, $query->path),
            new MigrationPage($query->page, $query->limit, $total, $total === 0 ? 0 : (int) ceil($total / $query->limit), count($page)),
            $page,
            $catalog->batches,
            $this->warnings($discovery, $catalog, $history),
        );
    }

    private function summary(HistoryView $history, MigrationQuery $query, CatalogResult $catalog): string
    {
        if ($query->filtered() && $history->reason === HistoryView::OPENED) {
            return 'Counts follow the requested filters. Warnings cover the full inspection.';
        }

        return match ($history->reason) {
            HistoryView::UNSUPPORTED => 'This database driver is recognised, but this release does not open it.',
            HistoryView::FAILED => 'The migration repository could not be read.',
            HistoryView::SQLITE => 'The sqlite database is not available for a read-only migration inspection.',
            HistoryView::TABLE => 'The migration repository table could not be read safely.',
            HistoryView::MISSING => 'The migration repository is not installed.',
            default => $history->empty && $catalog->fileCount === 0
                ? 'No migration files or repository records were found.'
                : 'Migration files were compared with the repository.',
        };
    }

    /**
     * @return list<string>
     */
    private function warnings(DiscoveryResult $discovery, CatalogResult $catalog, HistoryView $history): array
    {
        $warnings = [];

        if ($discovery->truncated) {
            $warnings[] = 'Migration discovery stopped at the file limit.';
        }

        if ($discovery->unreadable) {
            $warnings[] = 'A registered migration path could not be read.';
        }

        if ($discovery->outside) {
            $warnings[] = 'A migration path was skipped because it is outside the application.';
        }

        if ($discovery->withheld || $catalog->withheld || $history->withheld) {
            $warnings[] = 'A migration name was withheld because it is not a plain identifier.';
        }

        if ($catalog->duplicates) {
            $warnings[] = 'Duplicate migration names were found.';
        }

        if ($catalog->missing) {
            $warnings[] = 'Migration records have no corresponding file.';
        }

        if ($history->reason === HistoryView::MISSING) {
            $warnings[] = 'The migration repository is not installed.';
        }

        if ($catalog->batchesTruncated) {
            $warnings[] = 'Batch summary stopped at the batch limit.';
        }

        return $warnings;
    }

    private function empty(MigrationQuery $query, ?string $name, ?string $driver, ConnectionStatus $status, ?string $table, string $inspectedAt, string $summary): MigrationInspection
    {
        return new MigrationInspection(
            new MigrationConnection($name, $driver, $status),
            new MigrationRepositoryInfo(false, false, $table),
            new MigrationSummary(0, 0, 0, 0, 0, 0, $inspectedAt, $summary),
            new MigrationScope($query->filtered(), $query->name, $query->status, $query->batch, $query->path),
            new MigrationPage($query->page, $query->limit, 0, 0, 0),
            [],
            [],
            [],
        );
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function sqliteIsReadable(array $connection): bool
    {
        $driver = $connection['driver'] ?? null;

        if (! is_string($driver) || strtolower($driver) !== 'sqlite') {
            return true;
        }

        $database = $connection['database'] ?? null;

        if ($database === ':memory:') {
            return true;
        }

        return is_string($database) && $database !== '' && is_file($database);
    }

    private function table(): ?string
    {
        $configured = $this->repository->get('database.migrations');

        if (is_string($configured)) {
            $name = $configured;
        } elseif (is_array($configured)) {
            $name = $configured['table'] ?? 'migrations';
        } elseif ($configured === null) {
            $name = 'migrations';
        } else {
            return null;
        }

        if (! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) !== 1) {
            return null;
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private function allowed(?string $default): array
    {
        $allowed = [];

        if (is_string($default) && SafeName::isIdentifier($default)) {
            $allowed[] = $default;
        }

        foreach ($this->config->migrationsConnections() as $name) {
            if (SafeName::isIdentifier($name) && ! in_array($name, $allowed, true)) {
                $allowed[] = $name;
            }
        }

        return $allowed;
    }
}
