<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Inspectors\ErrorAggregateInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:errors')]
final class ErrorsCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:errors
        {--channel= : Configured file log channel name}
        {--class= : Literal exception class name}
        {--severity= : Minimum severity}
        {--category= : Exception category}
        {--since= : Include records at or after this timestamp}
        {--until= : Include records at or before this timestamp}
        {--min-count=1 : Minimum occurrences for a group}
        {--sort=occurrences : occurrences or last_seen}
        {--page=1 : Page number, from 1 to 10000}
        {--limit=25 : Page size, from 1 to 100}
        {--summary : Return summary metrics only}
        {--json : Output the error inspection as JSON on standard output}';

    protected $description = 'Show grouped exceptions or error summary metrics';

    protected $help = <<<'HELP'
        Prints the same report as beacon.get_error_aggregates, or beacon.get_error_summary
        when --summary is set.

        Requires the "error_aggregate" capability, or "error_summary" with --summary.
        The command is read-only. Counts are for the inspected window only.

        Exit codes:
          0  the report was printed
          1  Beacon or the required capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        $summaryOnly = $this->option('summary') === true;
        $inspector = $summaryOnly ? 'error_summary' : ErrorAggregateInspector::NAME;
        $capability = $summaryOnly ? 'error_summary' : 'error_aggregate';

        try {
            $data = $beacon->inspect(Principal::local(), $inspector, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The ' . $capability . ' capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=' . $capability . ' to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The ' . $inspector . ' inspector is disabled.' : 'The ' . $inspector . ' inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.' . $inspector . '" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidLogQueryException $exception) {
            return $this->reject($exception->getMessage(), null, self::EXIT_DENIED, 'not_allowed');
        } catch (\Throwable $exception) {
            return $this->failed($exception);
        }

        if ($this->option('json')) {
            $this->writeJson($data);
        } else {
            $this->render($data, $summaryOnly);
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
            'min_count' => $this->integerOption('min-count'),
        ];

        foreach (['channel', 'class', 'severity', 'category', 'since', 'until', 'sort'] as $option) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters[$option] = $value;
            }
        }

        return $parameters;
    }

    private function integerOption(string $name): int
    {
        $value = $this->option($name);

        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw InvalidLogQueryException::filter('Error aggregation rejected a non-integer filter.');
        }

        return (int) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data, bool $summaryOnly): void
    {
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Errors');
        $this->newLine();

        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $this->components->twoColumnDetail('Matched', $this->count($summary['matched_records'] ?? null));
        $this->components->twoColumnDetail('Groups', $this->count($summary['unique_groups'] ?? null));
        $this->components->twoColumnDetail('Returned', $this->count($summary['returned_groups'] ?? null));
        $this->newLine();

        if ($summaryOnly) {
            return;
        }

        $groups = $data['groups'] ?? null;

        if (! is_array($groups)) {
            return;
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $class = is_string($group['class_name'] ?? null) ? $group['class_name'] : 'Exception';
            $count = is_int($group['occurrences'] ?? null) ? $group['occurrences'] : 0;
            $message = is_string($group['message'] ?? null) ? $group['message'] : '';
            $this->line(sprintf('  %dx  %s', $count, OutputFormatter::escape($class)));
            $this->line('    <fg=gray>' . OutputFormatter::escape($message) . '</>');
        }

        $this->newLine();
    }

    private function count(mixed $value): string
    {
        return is_int($value) ? (string) $value : '0';
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:errors could not collect error aggregates (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Error aggregation is currently unavailable.',
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
