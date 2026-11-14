<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidRequestQueryException;
use Beacon\Inspectors\RequestSummaryInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:request-summary')]
final class RequestSummaryCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:request-summary
        {--since= : Include records at or after this timestamp}
        {--until= : Include records at or before this timestamp}
        {--method= : HTTP method filter}
        {--route= : Route name or URI filter}
        {--status= : HTTP status code}
        {--min-duration-ms= : Minimum duration in milliseconds}
        {--had-exception= : Filter by exception outcome true or false}
        {--correlation-id= : Request or correlation identifier}
        {--page=1 : Page number, from 1 to 10000}
        {--limit=25 : Page size, from 1 to 100}
        {--json : Output the request summary as JSON on standard output}';

    protected $description = 'Show request summary metrics from Beacon storage';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_request_summary MCP tool.

        Requires the "request_summary" capability (BEACON_ALLOWED_CAPABILITIES). The
        command is read-only. Allowing only "requests" does not allow this command.
        Bodies, cookies, Authorization headers, and query strings are never stored
        or printed.

        Exit codes:
          0  the report was printed
          1  Beacon or the request_summary capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), RequestSummaryInspector::NAME, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The request_summary capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=request_summary to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The request_summary inspector is disabled.' : 'The request_summary inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.request_summary" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidRequestQueryException $exception) {
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

        foreach (['since', 'until', 'method', 'route', 'correlation-id'] as $option) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters[str_replace('-', '_', $option)] = $value;
            }
        }

        $status = $this->option('status');

        if (is_string($status) && $status !== '') {
            $parameters['status'] = $this->integerOption('status');
        }

        $minDuration = $this->option('min-duration-ms');

        if (is_string($minDuration) && $minDuration !== '') {
            $parameters['min_duration_ms'] = $this->integerOption('min-duration-ms');
        }

        $hadException = $this->option('had-exception');

        if (is_string($hadException) && $hadException !== '') {
            $parameters['had_exception'] = $hadException;
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
            throw InvalidRequestQueryException::filter('Request inspection rejected a non-integer filter.');
        }

        return (int) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Request summary');
        $this->newLine();

        $summary = $data['summary'] ?? null;

        if (! is_array($summary)) {
            return;
        }

        $this->components->twoColumnDetail('Inspected', $this->count($summary['inspected'] ?? null));
        $this->components->twoColumnDetail('Returned', $this->count($summary['returned'] ?? null));
        $this->components->twoColumnDetail('With exceptions', $this->count($summary['with_exceptions'] ?? null));
        $this->components->twoColumnDetail('Average ms', $this->metric($summary['average_ms'] ?? null));
        $this->components->twoColumnDetail('Median ms', $this->metric($summary['median_ms'] ?? null));
        $this->components->twoColumnDetail('P95 ms', $this->metric($summary['p95_ms'] ?? null));
        $this->newLine();

        $limitation = $summary['limitation'] ?? null;

        if (is_string($limitation) && $limitation !== '') {
            $this->line('  <fg=gray>' . OutputFormatter::escape($limitation) . '</>');
            $this->newLine();
        }
    }

    private function count(mixed $value): string
    {
        return is_int($value) ? (string) $value : '0';
    }

    private function metric(mixed $value): string
    {
        return is_float($value) || is_int($value) ? (string) $value : '-';
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:request-summary could not collect a request summary (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Request summary is currently unavailable.',
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
