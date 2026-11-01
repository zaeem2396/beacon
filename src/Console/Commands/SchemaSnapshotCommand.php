<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Comparison\SnapshotFiles;
use Beacon\Contracts\Authorizer;
use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidSchemaQueryException;
use Beacon\Exceptions\InvalidSnapshotException;
use Beacon\Inspectors\SchemaSnapshotInspector;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:schema-snapshot')]
final class SchemaSnapshotCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:schema-snapshot
        {--connection= : Laravel connection name. Defaults to the application default.}
        {--output= : Snapshot file name inside storage/beacon/snapshots}
        {--json : Output the snapshot as JSON on standard output}';

    protected $description = 'Capture a read-only schema snapshot for an allowed database connection';

    protected $help = <<<'HELP'
        Prints the same snapshot as beacon.create_schema_snapshot.

        Requires the schema_snapshot and schema capabilities. The command does not
        read table rows or accept SQL. --output is a file name, not a path.
        Exit 0 means the snapshot was printed or written.

        Exit codes:
          0  the snapshot was printed or written
          1  Beacon or a required capability does not allow this command, or the name is invalid
          2  the snapshot could not be collected
        HELP;

    public function handle(Beacon $beacon, Authorizer $authorizer): int
    {
        try {
            if (! $authorizer->decide(Principal::local(), InspectionCapability::Schema->capability())->allowed) {
                return $this->reject('The schema capability is not allowed.', 'Set BEACON_ALLOWED_CAPABILITIES=schema,schema_snapshot to allow it.', self::EXIT_DENIED);
            }

            $data = $beacon->inspect(Principal::local(), SchemaSnapshotInspector::NAME, $this->parameters())->toArray();
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $output = $this->option('output');

            if (is_string($output) && $output !== '') {
                $files = new SnapshotFiles(storage_path('beacon/snapshots'), storage_path());
                $files->write($output, $encoded . "\n");
                $this->components->info('Wrote snapshot ' . $output);
            }
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : (str_contains($exception->getMessage(), 'schema capability')
                        ? 'The schema capability is not allowed.'
                        : 'The schema_snapshot capability is not allowed.'),
                null,
                self::EXIT_DENIED,
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The schema snapshot inspector is disabled.' : 'The schema snapshot inspector is not registered.',
                $disabled ? 'Enable it under inspectors.schema_snapshot in config/beacon.php.' : null,
                self::EXIT_DENIED,
            );
        } catch (InvalidSchemaQueryException | InvalidSnapshotException $exception) {
            return $this->reject($exception->getMessage(), null, self::EXIT_DENIED);
        } catch (\Throwable $exception) {
            return $this->failed($exception);
        }

        if ($this->option('json')) {
            $this->writeJson($encoded);
        } elseif (! is_string($output) || $output === '') {
            $this->render($data);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function parameters(): array
    {
        $connection = $this->option('connection');

        if (! is_string($connection) || $connection === '') {
            return [];
        }

        return ['connection' => $connection];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): void
    {
        $snapshot = $data['snapshot'] ?? null;
        $metadata = is_array($snapshot) ? ($snapshot['metadata'] ?? null) : null;
        $objects = is_array($snapshot) ? ($snapshot['objects'] ?? null) : null;
        $this->components->twoColumnDetail('Identifier', is_array($metadata) && is_string($metadata['identifier'] ?? null) ? $metadata['identifier'] : 'unknown');
        $this->components->twoColumnDetail('Driver', is_array($metadata) && is_string($metadata['driver'] ?? null) ? $metadata['driver'] : 'unknown');
        $this->components->twoColumnDetail('Objects', is_array($objects) ? (string) count($objects) : '0');
        $this->components->twoColumnDetail('Complete', is_array($metadata) && ($metadata['complete'] ?? false) === true ? 'yes' : 'no');
    }

    private function writeJson(string $encoded): void
    {
        $output = $this->output;

        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getOutput();
        }

        $output->writeln($encoded, OutputInterface::OUTPUT_RAW);
    }

    private function reject(string $message, ?string $hint, int $exitCode): int
    {
        $this->components->error($message);

        if ($hint !== null) {
            $this->line('  ' . OutputFormatter::escape($hint));
        }

        return $exitCode;
    }

    private function failed(\Throwable $exception): int
    {
        report($exception);
        $this->components->error('The schema snapshot could not be collected.');

        return self::EXIT_FAILED;
    }
}
