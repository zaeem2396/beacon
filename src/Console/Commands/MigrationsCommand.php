<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidMigrationQueryException;
use Beacon\Inspectors\MigrationInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:migrations')]
final class MigrationsCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:migrations
        {--connection= : Laravel connection name. Defaults to the application default. Additional names must be configured.}
        {--status= : executed, pending, missing_file, duplicate, or unknown}
        {--batch= : Repository batch number}
        {--filter= : Literal substring of the migration name}
        {--path= : Relative registered migration directory}
        {--page=1 : Page number, from 1 to 10000}
        {--limit=25 : Page size, from 1 to 100}
        {--json : Output the migration inspection as JSON on standard output}';

    protected $description = 'Show read-only migration files and repository history';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_migrations MCP tool.

        Requires the "migrations" capability (BEACON_ALLOWED_CAPABILITIES). The command
        is read-only. It does not run migrations or migration PHP. MySQL, MariaDB, and
        SQLite are read. PostgreSQL and SQL Server are identified and not opened.
        A missing migrations table is reported and is not created.

        Exit codes:
          0  the report was printed
          1  Beacon or the migrations capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), MigrationInspector::NAME, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The migrations capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=migrations to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The migrations inspector is disabled.' : 'The migrations inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.migrations" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidMigrationQueryException $exception) {
            return $this->reject($exception->getMessage(), null, self::EXIT_DENIED, 'not_allowed');
        } catch (\Throwable $exception) {
            return $this->failed($exception);
        }

        if ($this->option('json')) {
            $this->writeJson($data);
        } else {
            $this->render($data);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, int|string>
     */
    private function parameters(): array
    {
        $parameters = [
            'page' => $this->integerOption('page'),
            'limit' => $this->integerOption('limit'),
        ];

        foreach (['connection' => 'connection', 'status' => 'status', 'filter' => 'name', 'path' => 'path'] as $option => $parameter) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters[$parameter] = $value;
            }
        }

        $batch = $this->option('batch');

        if (is_string($batch) && $batch !== '') {
            $parameters['batch'] = $this->integerOption('batch');
        }

        return $parameters;
    }

    private function integerOption(string $key): int
    {
        $value = $this->option($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1 && strlen($value) < 8) {
            return (int) $value;
        }

        throw InvalidMigrationQueryException::type($key);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $connection = is_array($data['connection'] ?? null) ? $data['connection'] : [];
        $repository = is_array($data['repository'] ?? null) ? $data['repository'] : [];
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Migrations');
        $this->newLine();
        $this->components->twoColumnDetail('Connection', $this->text($connection['name'] ?? null));
        $this->components->twoColumnDetail('Driver', $this->text($connection['driver'] ?? null));
        $this->components->twoColumnDetail('Status', $this->status($connection['status'] ?? null));
        $this->components->twoColumnDetail('Repository', $this->text($repository['table'] ?? null));
        $this->components->twoColumnDetail('Executed', $this->count($summary['executed'] ?? null));
        $this->components->twoColumnDetail('Pending', $this->count($summary['pending'] ?? null));
        $this->components->twoColumnDetail('Missing files', $this->count($summary['missing_files'] ?? null));
        $this->newLine();

        $migrations = $data['migrations'] ?? null;

        if (is_array($migrations)) {
            foreach ($migrations as $migration) {
                if (! is_array($migration)) {
                    continue;
                }

                $detail = $this->text($migration['status'] ?? null);
                $batch = $migration['batch'] ?? null;

                if (is_int($batch)) {
                    $detail .= '  batch ' . $batch;
                }

                $this->components->twoColumnDetail($this->text($migration['name'] ?? null), $detail);
            }
        }

        $summaryText = $summary['summary'] ?? null;

        if (is_string($summaryText) && $summaryText !== '') {
            $this->newLine();
            $this->line('  <fg=gray>' . OutputFormatter::escape($summaryText) . '</>');
            $this->newLine();
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'unknown';
    }

    private function count(mixed $value): string
    {
        return is_int($value) ? (string) $value : 'unknown';
    }

    private function status(mixed $value): string
    {
        $label = $this->text($value);

        return match ($label) {
            'connected' => '<fg=green>connected</>',
            'unavailable' => '<fg=red>unavailable</>',
            'unsupported' => '<fg=yellow>unsupported</>',
            default => '<fg=gray>unknown</>',
        };
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:migrations could not collect a migration inspection (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Migration inspection is currently unavailable.',
            null,
            self::EXIT_FAILED,
            'failed',
        );
    }

    private function reject(string $message, ?string $hint, int $exitCode, string $error): int
    {
        if ($this->option('json')) {
            $payload = ['error' => $error, 'message' => $message];

            if ($hint !== null) {
                $payload['hint'] = $hint;
            }

            $this->writeJson($payload);

            return $exitCode;
        }

        $this->components->error($message);

        if ($hint !== null) {
            $this->line('  <fg=gray>' . OutputFormatter::escape($hint) . '</>');
        }

        return $exitCode;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(array $data): void
    {
        $this->output->writeln(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW,
        );
    }
}
