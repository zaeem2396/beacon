<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidDatabaseQueryException;
use Beacon\Inspectors\DatabaseInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:database')]
final class DatabaseCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:database
        {--json : Output the database status as JSON on standard output}';

    protected $description = 'Show read-only status for the default database connection';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_database_status MCP tool.

        Requires the "database" capability (BEACON_ALLOWED_CAPABILITIES). The command is
        read-only and does not accept a connection name or SQL. MySQL, MariaDB, and
        SQLite are probed. PostgreSQL and SQL Server are identified and not opened.
        Exit 0 means the report was printed. The connection status is in the output.

        Exit codes:
          0  the report was printed
          1  Beacon or the database capability does not allow this command
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), DatabaseInspector::NAME)->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The database capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=database to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The database inspector is disabled.' : 'The database inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.database" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidDatabaseQueryException $exception) {
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
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Database');
        $this->newLine();
        $this->components->twoColumnDetail('Connection', $this->text($data['connection'] ?? null));
        $this->components->twoColumnDetail('Driver', $this->text($data['driver'] ?? null));
        $this->components->twoColumnDetail('Status', $this->status($data['status'] ?? null));
        $this->components->twoColumnDetail('Version', $this->text($data['version'] ?? null));
        $latency = $data['latency_ms'] ?? null;
        $this->components->twoColumnDetail('Latency', is_int($latency) ? $latency . ' ms' : 'unknown');
        $this->newLine();
        $summary = $data['summary'] ?? null;

        if (is_string($summary) && $summary !== '') {
            $this->line('  <fg=gray>' . OutputFormatter::escape($summary) . '</>');
            $this->newLine();
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'unknown';
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
                sprintf('beacon:database could not collect a database status (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Database status is currently unavailable.',
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
