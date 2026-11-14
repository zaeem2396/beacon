<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidRuntimeQueryException;
use Beacon\Inspectors\QueueInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:queues')]
final class QueuesCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:queues
        {--json : Output the queue status as JSON on standard output}';

    protected $description = 'Show queue configuration and safely observable backend state';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_queue_status MCP tool.

        Requires the "queue" capability (BEACON_ALLOWED_CAPABILITIES). The command is
        read-only and does not accept arguments. Worker processes are not inspected.

        Exit codes:
          0  the report was printed
          1  Beacon or the queue capability does not allow this command
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), QueueInspector::NAME)->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The queue capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=queue to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The queue inspector is disabled.' : 'The queue inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.queue" in config/beacon.php.' : null,
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
        $this->line('  <options=bold>Laravel Beacon</>  Queues');
        $this->newLine();
        $this->components->twoColumnDetail('Default', $this->text($data['default_connection'] ?? null));
        $failed = $data['failed_job_count'] ?? null;
        $this->components->twoColumnDetail('Failed jobs', is_int($failed) ? (string) $failed : 'unknown');
        $this->newLine();

        $connections = $data['connections'] ?? null;

        if (is_array($connections)) {
            foreach ($connections as $connection) {
                if (! is_array($connection)) {
                    continue;
                }

                $name = is_string($connection['name'] ?? null) ? $connection['name'] : 'connection';
                $driver = is_string($connection['driver'] ?? null) ? $connection['driver'] : 'unknown';
                $status = is_string($connection['backend_status'] ?? null) ? $connection['backend_status'] : 'unknown';
                $this->components->twoColumnDetail($name, $driver . ' (' . $status . ')');
            }
        }

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

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:queues could not collect queue status (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Queue status is currently unavailable.',
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
