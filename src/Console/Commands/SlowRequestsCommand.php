<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidRequestQueryException;
use Beacon\Inspectors\SlowRequestInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:slow-requests')]
final class SlowRequestsCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:slow-requests
        {--since= : Include records at or after this timestamp}
        {--until= : Include records at or before this timestamp}
        {--method= : HTTP method filter}
        {--route= : Route name or URI filter}
        {--status= : HTTP status code}
        {--min-duration-ms= : Minimum duration in milliseconds}
        {--window-minutes= : Analysis window in minutes when since and until are omitted}
        {--page=1 : Page number, from 1 to 10000}
        {--limit= : Page size, from 1 to 100}
        {--max-endpoints= : Accepted for shared filters. Used by the summary command}
        {--json : Output the slow request inspection as JSON on standard output}';

    protected $description = 'Show recorded HTTP requests that exceeded duration thresholds';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_slow_requests MCP tool.

        Requires the "slow_requests" capability (BEACON_ALLOWED_CAPABILITIES). The
        command is read-only. Allowing only "requests" does not allow this command.
        Bodies, cookies, Authorization headers, and query strings are never stored
        or printed. Collection must be enabled and the Beacon requests migration
        must be published and run first. Equality with the threshold is not slow.

        Exit codes:
          0  the report was printed
          1  Beacon or the slow_requests capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), SlowRequestInspector::NAME, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The slow_requests capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=slow_requests to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The slow_requests inspector is disabled.' : 'The slow_requests inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.slow_requests" in config/beacon.php.' : null,
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
        ];

        foreach (['since', 'until', 'method', 'route'] as $option) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters[$option] = $value;
            }
        }

        foreach (['status', 'min-duration-ms', 'window-minutes', 'limit', 'max-endpoints'] as $option) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters[str_replace('-', '_', $option)] = $this->integerOption($option);
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
            throw InvalidRequestQueryException::filter('Slow request inspection rejected a non-integer filter.');
        }

        return (int) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Slow requests');
        $this->newLine();

        $this->components->twoColumnDetail('Returned', $this->count($data['returned'] ?? null));
        $this->components->twoColumnDetail('Slow', $this->count($data['slow_count'] ?? null));
        $this->components->twoColumnDetail('Inspected', $this->count($data['inspected'] ?? null));
        $this->components->twoColumnDetail('Threshold ms', $this->count($data['threshold_ms'] ?? null));
        $this->newLine();

        $requests = $data['requests'] ?? null;

        if (! is_array($requests)) {
            return;
        }

        foreach ($requests as $request) {
            if (! is_array($request)) {
                continue;
            }

            $method = is_string($request['method'] ?? null) ? $request['method'] : 'UNKNOWN';
            $uri = is_string($request['route_uri'] ?? null) ? $request['route_uri'] : 'unmatched';
            $duration = isset($request['duration_ms']) && is_int($request['duration_ms'])
                ? $request['duration_ms'] . 'ms'
                : '-';
            $excess = isset($request['excess_ms']) && is_int($request['excess_ms'])
                ? '+' . $request['excess_ms'] . 'ms'
                : '-';
            $rank = is_string($request['rank'] ?? null) ? $request['rank'] : '-';
            $this->line(sprintf(
                '  %s %s  <fg=gray>%s %s %s</>',
                OutputFormatter::escape($method),
                OutputFormatter::escape($uri),
                OutputFormatter::escape($duration),
                OutputFormatter::escape($excess),
                OutputFormatter::escape($rank),
            ));
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
                sprintf('beacon:slow-requests could not collect slow requests (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Slow request inspection is currently unavailable.',
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
