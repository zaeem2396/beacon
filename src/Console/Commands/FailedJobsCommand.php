<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Inspectors\FailedJobsInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:failed-jobs')]
final class FailedJobsCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:failed-jobs
        {--limit=25 : Maximum failed jobs to return, from 1 to 100}
        {--json : Output the failed job inspection as JSON on standard output}';

    protected $description = 'Show failed jobs without payloads';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_failed_jobs MCP tool.

        Requires the "failed_jobs" capability (BEACON_ALLOWED_CAPABILITIES). The command
        is read-only. Payloads are never selected. Exception summaries are sanitized.

        Exit codes:
          0  the report was printed
          1  Beacon or the failed_jobs capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), FailedJobsInspector::NAME, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The failed_jobs capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=failed_jobs to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The failed_jobs inspector is disabled.' : 'The failed_jobs inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.failed_jobs" in config/beacon.php.' : null,
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
            $this->render($data);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function parameters(): array
    {
        return ['limit' => $this->integerOption('limit')];
    }

    private function integerOption(string $name): int
    {
        $value = $this->option($name);

        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw InvalidLogQueryException::filter('Failed job inspection rejected the limit.');
        }

        return (int) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Failed jobs');
        $this->newLine();
        $this->components->twoColumnDetail('Driver', $this->text($data['driver'] ?? null));
        $this->components->twoColumnDetail('Returned', $this->count($data['returned'] ?? null));
        $this->components->twoColumnDetail('Total', $this->count($data['total'] ?? null, 'unknown'));
        $this->newLine();

        $jobs = $data['jobs'] ?? null;

        if (! is_array($jobs)) {
            return;
        }

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            $id = is_string($job['failed_job_id'] ?? null) ? $job['failed_job_id'] : 'job';
            $summary = is_string($job['exception_summary'] ?? null) ? $job['exception_summary'] : '';
            $this->line('  ' . OutputFormatter::escape($id));
            $this->line('    <fg=gray>' . OutputFormatter::escape($summary) . '</>');
        }

        $this->newLine();
    }

    private function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'unknown';
    }

    private function count(mixed $value, string $fallback = '0'): string
    {
        return is_int($value) ? (string) $value : $fallback;
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:failed-jobs could not collect failed jobs (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Failed job inspection is currently unavailable.',
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
