<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidRuntimeQueryException;
use Beacon\Inspectors\SchedulerInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:scheduler')]
final class SchedulerCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:scheduler
        {--json : Output the scheduler inspection as JSON on standard output}';

    protected $description = 'Show registered scheduled task definitions';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_scheduler_status MCP tool.

        Requires the "scheduler" capability (BEACON_ALLOWED_CAPABILITIES). The command
        is read-only and does not accept arguments. Tasks are listed and not executed.

        Exit codes:
          0  the report was printed
          1  Beacon or the scheduler capability does not allow this command
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), SchedulerInspector::NAME)->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The scheduler capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=scheduler to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The scheduler inspector is disabled.' : 'The scheduler inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.scheduler" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidRuntimeQueryException $exception) {
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
        $this->line('  <options=bold>Laravel Beacon</>  Scheduler');
        $this->newLine();
        $this->components->twoColumnDetail('Returned', $this->count($data['returned'] ?? null));
        $this->newLine();

        $tasks = $data['tasks'] ?? null;

        if (! is_array($tasks)) {
            return;
        }

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $description = is_string($task['description'] ?? null) ? $task['description'] : 'Scheduled task';
            $expression = is_string($task['expression'] ?? null) ? $task['expression'] : 'unknown';
            $this->components->twoColumnDetail($expression, OutputFormatter::escape($description));
        }

        $this->newLine();
        $summary = $data['summary'] ?? null;

        if (is_string($summary) && $summary !== '') {
            $this->line('  <fg=gray>' . OutputFormatter::escape($summary) . '</>');
            $this->newLine();
        }
    }

    private function count(mixed $value): string
    {
        return is_int($value) ? (string) $value : '0';
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:scheduler could not collect scheduler status (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Scheduler status is currently unavailable.',
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
