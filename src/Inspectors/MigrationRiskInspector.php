<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\MigrationConnection;
use Beacon\Data\MigrationEntry;
use Beacon\Data\MigrationPage;
use Beacon\Data\MigrationRepositoryInfo;
use Beacon\Data\MigrationRiskInspection;
use Beacon\Data\RiskFinding;
use Beacon\Data\RiskScope;
use Beacon\Data\RiskSummary;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\InvalidRiskQueryException;
use Beacon\Health\Clock;
use Beacon\Health\SafeName;
use Beacon\Migrations\HistoryView;
use Beacon\Migrations\MigrationCatalog;
use Beacon\Migrations\MigrationHistory;
use Beacon\Migrations\MigrationPaths;
use Beacon\Migrations\MigrationQuery;
use Beacon\Migrations\MigrationScanner;
use Beacon\Migrations\MigrationStatus;
use Beacon\Risk\RiskEngine;
use Beacon\Risk\RiskQuery;
use Beacon\Risk\SchemaContext;
use Beacon\Risk\Severity;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Illuminate\Contracts\Config\Repository;

/**
 * Reports static migration risk findings.
 *
 * Discovery and repository status are reused from migration inspection.
 * Migration PHP is not included or executed, and migration SQL is not run.
 *
 * @implements Inspector<MigrationRiskInspection>
 */
final class MigrationRiskInspector implements Inspector
{
    public const NAME = 'migration_risk';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly Repository $repository,
        private readonly MigrationPaths $paths,
        private readonly MigrationScanner $scanner,
        private readonly MigrationHistory $history,
        private readonly MigrationCatalog $catalog,
        private readonly RiskEngine $engine,
        private readonly SchemaContext $schema,
        private readonly Clock $clock,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::MigrationRisk->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        $query = RiskQuery::from($parameters);
        $inspectedAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\+00:00');
        $timeout = $this->config->migrationRiskTimeout();

        if ($timeout === null) {
            return $this->empty($query, null, null, ConnectionStatus::Unknown, null, $inspectedAt, 'The migration risk timeout must be an integer from 1 to 5 seconds.');
        }

        $default = $this->repository->get('database.default');
        $allowed = $this->allowed(is_string($default) ? $default : null);
        $name = $query->connection ?? (is_string($default) ? $default : null);

        if (! is_string($name) || ! SafeName::isIdentifier($name) || ! in_array($name, $allowed, true)) {
            $summary = is_string($name) && SafeName::isIdentifier($name)
                ? 'The requested database connection is not allowed for migration risk analysis.'
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
            throw InvalidRiskQueryException::path();
        }

        $table = $this->table();
        $history = $this->history($connection, $driver, $timeout, $table);
        $catalog = $this->catalog->classify(
            $discovery->files,
            $history->batches,
            $history->available,
            new MigrationQuery(null, null, null, null, $query->path, 1, 100),
        );
        $selected = [];

        foreach ($catalog->migrations as $entry) {
            if ($this->selected($entry, $query)) {
                $selected[] = $entry;
            }
        }

        $enrich = $query->enrich && $this->schema->permitted();
        $result = $this->engine->analyze(base_path(), $selected, $name, $enrich);
        $findings = $this->filter($result->findings, $query);
        $page = array_slice($findings, ($query->page - 1) * $query->limit, $query->limit);
        $total = count($findings);

        return new MigrationRiskInspection(
            new MigrationConnection($name, $driver, $history->status),
            new MigrationRepositoryInfo($history->available, $history->empty, $table),
            $this->summary($query, $result->analyzed, $findings, $inspectedAt, $history),
            new RiskScope($query->filtered(), $query->migration, $query->path, $query->category, $query->minimumSeverity, $query->pending, $query->includeExecuted, $result->schemaApplied),
            new MigrationPage($query->page, $query->limit, $total, $total === 0 ? 0 : (int) ceil($total / $query->limit), count($page)),
            $page,
            $result->limitations,
            $this->warnings($discovery, $catalog, $history, $result->findingsTruncated, $result->schemaApplied, $result->schemaFailed, $result->schemaTruncated, $query->enrich),
        );
    }

    private function selected(MigrationEntry $entry, RiskQuery $query): bool
    {
        if ($query->migration !== null) {
            return $entry->name === $query->migration && $entry->fileExists;
        }

        if ($entry->status === MigrationStatus::Pending && $query->pending) {
            return true;
        }

        if ($entry->status === MigrationStatus::Executed && $query->includeExecuted) {
            return true;
        }

        return $entry->status === MigrationStatus::Duplicate && ($query->pending || $query->includeExecuted);
    }

    /**
     * @param list<RiskFinding> $findings
     *
     * @return list<RiskFinding>
     */
    private function filter(array $findings, RiskQuery $query): array
    {
        $filtered = [];

        foreach ($findings as $finding) {
            if ($query->category !== null && $finding->category !== $query->category) {
                continue;
            }

            if ($query->minimumSeverity !== null && ! $finding->severity->meets($query->minimumSeverity)) {
                continue;
            }

            $filtered[] = $finding;
        }

        return $filtered;
    }

    /**
     * @param list<RiskFinding> $findings
     */
    private function summary(RiskQuery $query, int $analyzed, array $findings, string $inspectedAt, HistoryView $history): RiskSummary
    {
        $counts = [
            Severity::Critical->value => 0,
            Severity::High->value => 0,
            Severity::Medium->value => 0,
            Severity::Low->value => 0,
            Severity::Informational->value => 0,
        ];
        $manual = 0;

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;

            if ($finding->manualReview) {
                $manual++;
            }
        }

        if ($query->filtered()) {
            $text = 'Counts follow the requested filters. Warnings cover the full inspection.';
        } elseif ($history->reason !== HistoryView::OPENED && $query->migration === null && ! $query->includeExecuted) {
            $text = 'The migration repository was not read, so pending migrations could not be selected.';
        } elseif ($query->migration !== null) {
            $text = 'The named migration was analyzed without executing it.';
        } elseif ($query->includeExecuted) {
            $text = 'Pending and executed migrations were analyzed without executing them.';
        } elseif ($analyzed === 0) {
            $text = 'No pending migration files were selected.';
        } else {
            $text = 'Pending migrations were analyzed without executing them.';
        }

        return new RiskSummary(
            $analyzed,
            count($findings),
            $counts[Severity::Critical->value],
            $counts[Severity::High->value],
            $counts[Severity::Medium->value],
            $counts[Severity::Low->value],
            $counts[Severity::Informational->value],
            $manual,
            $inspectedAt,
            $text,
        );
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

    /**
     * @return list<string>
     */
    private function warnings(
        \Beacon\Migrations\DiscoveryResult $discovery,
        \Beacon\Migrations\CatalogResult $catalog,
        HistoryView $history,
        bool $findingsTruncated,
        bool $schemaApplied,
        bool $schemaFailed,
        bool $schemaTruncated,
        bool $enrichRequested,
    ): array {
        $warnings = [];

        if ($history->reason === HistoryView::FAILED) {
            $warnings[] = 'The migration repository could not be read.';
        }

        if ($history->reason === HistoryView::MISSING) {
            $warnings[] = 'The migration repository is not installed.';
        }

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

        if ($findingsTruncated) {
            $warnings[] = 'Finding output stopped at the finding limit.';
        }

        if ($schemaFailed) {
            $warnings[] = 'Schema metadata could not be read. Findings use migration source only.';
        } elseif ($schemaTruncated) {
            $warnings[] = 'Schema context stopped at the table limit.';
        } elseif ($enrichRequested && ! $schemaApplied) {
            $warnings[] = 'Schema metadata was not applied.';
        }

        return array_slice($warnings, 0, 8);
    }

    private function empty(RiskQuery $query, ?string $name, ?string $driver, ConnectionStatus $status, ?string $table, string $inspectedAt, string $summary): MigrationRiskInspection
    {
        return new MigrationRiskInspection(
            new MigrationConnection($name, $driver, $status),
            new MigrationRepositoryInfo(false, false, $table),
            new RiskSummary(0, 0, 0, 0, 0, 0, 0, 0, $inspectedAt, $summary),
            new RiskScope($query->filtered(), $query->migration, $query->path, $query->category, $query->minimumSeverity, $query->pending, $query->includeExecuted, false),
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

        foreach ($this->config->migrationRiskConnections() as $name) {
            if (SafeName::isIdentifier($name) && ! in_array($name, $allowed, true)) {
                $allowed[] = $name;
            }
        }

        return $allowed;
    }
}
