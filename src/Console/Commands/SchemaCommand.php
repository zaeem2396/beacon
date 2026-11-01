<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidSchemaQueryException;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:schema')]
final class SchemaCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:schema
        {--connection= : Laravel connection name. Defaults to the application default. Additional names must be configured.}
        {--table= : Exact table or view name}
        {--no-views : Omit views}
        {--no-columns : Omit column details}
        {--no-indexes : Omit indexes and unique constraints}
        {--no-foreign-keys : Omit foreign keys}
        {--page=1 : Page number, from 1 to 10000}
        {--limit=25 : Page size, from 1 to 50}
        {--json : Output the schema inspection as JSON on standard output}';

    protected $description = 'Show read-only schema metadata for an allowed database connection';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_database_schema MCP tool.

        Requires the "schema" capability (BEACON_ALLOWED_CAPABILITIES). The command is
        read-only. MySQL and MariaDB are inspected. Other drivers are identified and
        not opened. It does not accept SQL or connection settings. Exit 0 means the
        report was printed. The connection status is in the output.

        Exit codes:
          0  the report was printed
          1  Beacon or the schema capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), SchemaInspector::NAME, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The schema capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=schema to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The schema inspector is disabled.' : 'The schema inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.schema" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidSchemaQueryException $exception) {
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
     * @return array<string, bool|int|string>
     */
    private function parameters(): array
    {
        $parameters = [
            'page' => $this->integerOption('page'),
            'limit' => $this->integerOption('limit'),
        ];
        $connection = $this->option('connection');
        $table = $this->option('table');

        if (is_string($connection) && $connection !== '') {
            $parameters['connection'] = $connection;
        }

        if (is_string($table) && $table !== '') {
            $parameters['table'] = $table;
        }

        if ($this->option('no-views') === true) {
            $parameters['include_views'] = false;
        }

        if ($this->option('no-columns') === true) {
            $parameters['include_columns'] = false;
        }

        if ($this->option('no-indexes') === true) {
            $parameters['include_indexes'] = false;
        }

        if ($this->option('no-foreign-keys') === true) {
            $parameters['include_foreign_keys'] = false;
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

        throw InvalidSchemaQueryException::type($key);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $connection = is_array($data['connection'] ?? null) ? $data['connection'] : [];
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Schema');
        $this->newLine();
        $this->components->twoColumnDetail('Connection', $this->text($connection['name'] ?? null));
        $this->components->twoColumnDetail('Driver', $this->text($connection['driver'] ?? null));
        $this->components->twoColumnDetail('Database', $this->text($connection['database'] ?? null));
        $this->components->twoColumnDetail('Status', $this->status($connection['status'] ?? null));
        $this->components->twoColumnDetail('Version', $this->text($connection['version'] ?? null));
        $this->components->twoColumnDetail('Tables', $this->count($summary['table_count'] ?? null));
        $this->components->twoColumnDetail('Views', $this->count($summary['view_count'] ?? null));
        $this->newLine();

        $objects = $data['objects'] ?? null;

        if (! is_array($objects)) {
            return;
        }

        foreach ($objects as $object) {
            if (! is_array($object)) {
                continue;
            }

            $label = $this->text($object['name'] ?? null);
            $detail = $this->text($object['type'] ?? null);
            $columns = $object['column_count'] ?? null;

            if (is_int($columns)) {
                $detail .= '  ' . $columns . ' columns';
            }

            $this->components->twoColumnDetail($label, $detail);
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
                sprintf('beacon:schema could not collect a schema inspection (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Schema inspection is currently unavailable.',
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
