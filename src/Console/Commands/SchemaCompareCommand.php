<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Comparison\SnapshotFiles;
use Beacon\Contracts\Authorizer;
use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidComparisonQueryException;
use Beacon\Exceptions\InvalidSnapshotException;
use Beacon\Inspectors\SchemaComparisonInspector;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:schema-compare')]
final class SchemaCompareCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:schema-compare
        {baseline : Snapshot file name, or live}
        {candidate : Snapshot file name, or live}
        {--connection= : Laravel connection name used when a side is live}
        {--category= : table, column, primary_key, index, foreign_key, view, or metadata}
        {--severity= : Minimum severity}
        {--object= : Exact object name}
        {--detect-renames : Pair one added and one removed object when the structure matches}
        {--page=1 : Page number, from 1 to 10000}
        {--limit=100 : Page size, from 1 to 100}
        {--json : Output the comparison as JSON on standard output}';

    protected $description = 'Compare two schema snapshots, or one snapshot and the live schema';

    protected $help = <<<'HELP'
        Prints the same report as beacon.compare_schema_snapshots.

        Arguments are the word live or a file name in storage/beacon/snapshots.
        Paths are rejected. Requires the schema_compare capability. A live side
        also requires schema. Snapshot JSON is not executed and rows are not read.

        Exit codes:
          0  the report was printed
          1  Beacon or a required capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon, Authorizer $authorizer): int
    {
        try {
            if (! $authorizer->decide(Principal::local(), InspectionCapability::SchemaCompare->capability())->allowed) {
                return $this->reject('The schema_compare capability is not allowed.', 'Set BEACON_ALLOWED_CAPABILITIES=schema_compare to allow it.', self::EXIT_DENIED);
            }

            $parameters = $this->parameters();
            $live = ($parameters['baseline_live'] ?? false) === true || ($parameters['candidate_live'] ?? false) === true;

            if ($live && ! $authorizer->decide(Principal::local(), InspectionCapability::Schema->capability())->allowed) {
                return $this->reject('The schema capability is not allowed.', 'Set BEACON_ALLOWED_CAPABILITIES=schema,schema_compare to allow it.', self::EXIT_DENIED);
            }

            $data = $beacon->inspect(Principal::local(), SchemaComparisonInspector::NAME, $parameters)->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : (str_contains($exception->getMessage(), 'schema capability')
                        ? 'The schema capability is not allowed.'
                        : 'The schema_compare capability is not allowed.'),
                null,
                self::EXIT_DENIED,
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The schema comparison inspector is disabled.' : 'The schema comparison inspector is not registered.',
                null,
                self::EXIT_DENIED,
            );
        } catch (InvalidComparisonQueryException | InvalidSnapshotException $exception) {
            return $this->reject($exception->getMessage(), null, self::EXIT_DENIED);
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
            'detect_renames' => $this->option('detect-renames') === true,
        ];
        $baseline = $this->argument('baseline');
        $candidate = $this->argument('candidate');

        if (! is_string($baseline) || ! is_string($candidate)) {
            throw InvalidComparisonQueryException::selection();
        }

        $parameters = array_merge($parameters, $this->side('baseline', $baseline), $this->side('candidate', $candidate));

        foreach (['connection', 'category', 'severity', 'object'] as $key) {
            $value = $this->option($key);

            if (is_string($value) && $value !== '') {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * @return array<string, bool|string>
     */
    private function side(string $key, string $value): array
    {
        if ($value === 'live') {
            return [$key . '_live' => true];
        }

        $files = new SnapshotFiles(storage_path('beacon/snapshots'), storage_path());

        return [$key => $files->read($value)];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): void
    {
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $this->components->twoColumnDetail('Differences', is_int($summary['differences'] ?? null) ? (string) $summary['differences'] : '0');
        $this->components->twoColumnDetail('Complete', ($summary['complete'] ?? false) === true ? 'yes' : 'no');
        $differences = $data['differences'] ?? null;

        if (! is_array($differences)) {
            return;
        }

        foreach ($differences as $difference) {
            if (! is_array($difference)) {
                continue;
            }

            $severity = is_string($difference['severity'] ?? null) ? $difference['severity'] : 'unknown';
            $id = is_string($difference['id'] ?? null) ? $difference['id'] : 'difference';
            $explanation = is_string($difference['explanation'] ?? null) ? $difference['explanation'] : '';
            $this->line(sprintf('  %s  %s', $severity, $id));
            $this->line('    ' . OutputFormatter::escape($explanation));
        }
    }

    private function integerOption(string $key): int
    {
        $value = $this->option($key);

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw InvalidComparisonQueryException::type($key);
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
        $this->components->error('The schema comparison could not be collected.');

        return self::EXIT_FAILED;
    }
}
