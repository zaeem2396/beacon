<?php

declare(strict_types=1);

namespace Beacon\Migrations;

use Beacon\Data\MigrationConnection;
use Beacon\Health\LaravelDatabaseProbe;
use Beacon\Schema\SchemaSql;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;

/**
 * Reads Laravel's migration repository and does not create it.
 *
 * The connection is temporary. MySQL and MariaDB sessions are set read-only
 * before the repository is asked anything. createRepository is never called.
 */
final class RepositoryHistory implements MigrationHistory
{
    public function __construct(
        private readonly ConnectionFactory $factory,
    ) {
    }

    public function read(array $config, int $timeoutSeconds, string $table): HistoryResult
    {
        $driver = $config['driver'] ?? null;
        $driver = is_string($driver) ? strtolower($driver) : '';

        if (! in_array($driver, MigrationConnection::OPENED_DRIVERS, true)) {
            throw new \RuntimeException('Migration inspection does not open this driver.');
        }

        $connection = $this->factory->make(LaravelDatabaseProbe::prepare($config, $timeoutSeconds), 'beacon-migrations');

        try {
            $this->guard($connection, $driver);
            $repository = new DatabaseMigrationRepository(new FixedConnectionResolver($connection), $table);

            if (! $repository->repositoryExists()) {
                return HistoryResult::missing();
            }

            return $this->normalize($repository->getMigrationBatches());
        } finally {
            $connection->disconnect();
        }
    }

    private function guard(Connection $connection, string $driver): void
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $statement = 'set session transaction read only';

        if (! SchemaSql::isReadOnly($statement)) {
            throw new \RuntimeException('Migration inspection refused the statement.');
        }

        $connection->statement($statement);
    }

    /**
     * @param array<array-key, mixed> $batches
     */
    private function normalize(array $batches): HistoryResult
    {
        $records = [];
        $withheld = false;

        foreach ($batches as $name => $batch) {
            $number = $this->batch($batch);

            if (! is_string($name) || ! MigrationName::isSafe($name) || $number === null) {
                $withheld = true;

                continue;
            }

            $records[$name] = $number;
        }

        return new HistoryResult(true, $batches === [], $records, $withheld);
    }

    private function batch(mixed $batch): ?int
    {
        if (is_int($batch)) {
            return $batch >= 1 && $batch <= MigrationQuery::MAX_BATCH ? $batch : null;
        }

        if (is_string($batch) && preg_match('/^[1-9][0-9]{0,6}$/', $batch) === 1) {
            $number = (int) $batch;

            return $number <= MigrationQuery::MAX_BATCH ? $number : null;
        }

        return null;
    }
}
