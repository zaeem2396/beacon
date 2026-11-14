<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Inspectors\LogSummaryInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:logs')]
final class LogsCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:logs
        {--channel= : Configured file log channel name}
        {--limit=100 : Maximum records to summarize, from 1 to 200}
        {--json : Output the log summary as JSON on standard output}';

    protected $description = 'Show a summary of supported file log channels';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_log_summary MCP tool.

        Requires the "logs" capability (BEACON_ALLOWED_CAPABILITIES). The command is
        read-only. Message bodies are not printed. Only storage/logs file channels
        are read.

        Exit codes:
          0  the report was printed
          1  Beacon or the logs capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), LogSummaryInspector::NAME, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The logs capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=logs to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The logs inspector is disabled.' : 'The logs inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.logs" in config/beacon.php.' : null,
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
     * @return array<string, int|string>
     */
    private function parameters(): array
    {
        $parameters = [
            'limit' => $this->integerOption('limit'),
        ];
        $channel = $this->option('channel');

        if (is_string($channel) && $channel !== '') {
            $parameters['channel'] = $channel;
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
            throw InvalidLogQueryException::filter('Log summary rejected the limit.');
        }

        return (int) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Logs');
        $this->newLine();
        $this->components->twoColumnDetail('Inspected', $this->count($data['inspected'] ?? null));
        $this->components->twoColumnDetail('Errors', $this->count($data['error_count'] ?? null));
        $this->components->twoColumnDetail('Critical+', $this->count($data['critical_count'] ?? null));
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
                sprintf('beacon:logs could not collect a log summary (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Log summary is currently unavailable.',
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
