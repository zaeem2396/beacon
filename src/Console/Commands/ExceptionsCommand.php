<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Inspectors\ExceptionInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:exceptions')]
final class ExceptionsCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:exceptions
        {--channel= : Configured file log channel name}
        {--class= : Literal exception class name}
        {--severity= : Minimum severity}
        {--category= : Exception category}
        {--since= : Include records at or after this timestamp}
        {--until= : Include records at or before this timestamp}
        {--page=1 : Page number, from 1 to 10000}
        {--limit=25 : Page size, from 1 to 100}
        {--json : Output the exception inspection as JSON on standard output}';

    protected $description = 'Show recent exceptions from supported file log channels';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_recent_exceptions MCP tool.

        Requires the "exceptions" capability (BEACON_ALLOWED_CAPABILITIES). The command
        is read-only. Only storage/logs file channels are read. Messages are sanitized.

        Exit codes:
          0  the report was printed
          1  Beacon or the exceptions capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), ExceptionInspector::NAME, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The exceptions capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=exceptions to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The exceptions inspector is disabled.' : 'The exceptions inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.exceptions" in config/beacon.php.' : null,
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
            'page' => $this->integerOption('page'),
            'limit' => $this->integerOption('limit'),
        ];

        foreach (['channel', 'class', 'severity', 'category', 'since', 'until'] as $option) {
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
            throw InvalidLogQueryException::filter('Exception inspection rejected a non-integer filter.');
        }

        return (int) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Exceptions');
        $this->newLine();
        $this->components->twoColumnDetail('Returned', $this->count($data['returned'] ?? null));
        $this->components->twoColumnDetail('Inspected', $this->count($data['inspected'] ?? null));
        $this->newLine();

        $exceptions = $data['exceptions'] ?? null;

        if (! is_array($exceptions)) {
            return;
        }

        foreach ($exceptions as $exception) {
            if (! is_array($exception)) {
                continue;
            }

            $class = is_string($exception['class_name'] ?? null) ? $exception['class_name'] : 'Exception';
            $message = is_string($exception['message'] ?? null) ? $exception['message'] : '';
            $this->line('  ' . OutputFormatter::escape($class));
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
                sprintf('beacon:exceptions could not collect exceptions (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Exception inspection is currently unavailable.',
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
