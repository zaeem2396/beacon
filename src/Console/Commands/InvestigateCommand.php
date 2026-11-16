<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidInvestigationQueryException;
use Beacon\Inspectors\InvestigationContextInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:investigate')]
final class InvestigateCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:investigate
        {--request-id= : Investigate this request id}
        {--correlation-id= : Investigate this correlation id}
        {--exception-id= : Investigate this exception id}
        {--fingerprint= : Investigate this exception fingerprint}
        {--endpoint= : Investigate this route name or URI}
        {--since= : Include evidence at or after this timestamp}
        {--until= : Include evidence at or before this timestamp}
        {--severity= : Minimum log severity filter}
        {--window-minutes= : Default window when since and until are omitted}
        {--max-results= : Upper bound applied to per-source evidence counts}
        {--format=text : Output format: text or json}
        {--json : Output the investigation context as JSON on standard output}';

    protected $description = 'Build a normalized investigation context from Beacon evidence';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_investigation_context MCP tool.

        Requires the "investigation_context" capability (BEACON_ALLOWED_CAPABILITIES).
        The command is read-only. Allowing only requests or exceptions does not allow
        this command. Bodies, cookies, Authorization headers, query strings, and
        failed-job payloads are never printed. Deployment and configuration-change
        history are reported as unavailable when Beacon has no real source.

        Exit codes:
          0  the report was printed
          1  Beacon or the investigation_context capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), InvestigationContextInspector::NAME, $this->parameters())->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The investigation_context capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=investigation_context to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The investigation_context inspector is disabled.' : 'The investigation_context inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.investigation_context" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidInvestigationQueryException $exception) {
            return $this->reject($exception->getMessage(), null, self::EXIT_DENIED, 'not_allowed');
        } catch (\Throwable $exception) {
            return $this->failed($exception);
        }

        if ($this->wantsJson()) {
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
        $parameters = [];

        foreach ([
            'request-id' => 'request_id',
            'correlation-id' => 'correlation_id',
            'exception-id' => 'exception_id',
            'fingerprint' => 'fingerprint',
            'endpoint' => 'endpoint',
            'since' => 'since',
            'until' => 'until',
            'severity' => 'severity',
        ] as $option => $key) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters[$key] = $value;
            }
        }

        foreach (['window-minutes' => 'window_minutes', 'max-results' => 'max_results'] as $option => $key) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters[$key] = $this->integerOption($option);
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
            throw InvalidInvestigationQueryException::filter('Investigation rejected a non-integer filter.');
        }

        return (int) $value;
    }

    private function wantsJson(): bool
    {
        if ($this->option('json')) {
            return true;
        }

        $format = $this->option('format');

        return is_string($format) && strtolower($format) === 'json';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Investigation context');
        $this->newLine();

        $this->components->twoColumnDetail('Status', is_string($data['status'] ?? null) ? $data['status'] : '-');
        $this->components->twoColumnDetail('Completeness', is_string($data['completeness'] ?? null) ? $data['completeness'] : '-');
        $this->components->twoColumnDetail('Truncated', ! empty($data['truncated']) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Summary', is_string($data['summary'] ?? null) ? $data['summary'] : '-');
        $this->newLine();

        $subject = $data['primary_subject'] ?? null;

        if (is_array($subject)) {
            $this->line('  <options=bold>Primary subject</>');
            $this->components->twoColumnDetail('Kind', is_string($subject['kind'] ?? null) ? $subject['kind'] : '-');
            $this->components->twoColumnDetail('Summary', is_string($subject['summary'] ?? null) ? $subject['summary'] : '-');
            $this->newLine();
        }

        $this->renderList('Timeline', $data['timeline'] ?? null, static function (array $event): string {
            $stamp = is_string($event['timestamp'] ?? null) ? $event['timestamp'] : 'no-timestamp';
            $type = is_string($event['event_type'] ?? null) ? $event['event_type'] : 'event';
            $summary = is_string($event['summary'] ?? null) ? $event['summary'] : '';

            return sprintf('%s  %s  %s', $stamp, $type, $summary);
        });

        $this->renderList('Leads', $data['leads'] ?? null, static function (array $lead): string {
            $title = is_string($lead['title'] ?? null) ? $lead['title'] : 'Lead';
            $next = is_string($lead['next_inspection'] ?? null) ? $lead['next_inspection'] : '';

            return sprintf('%s — %s', $title, $next);
        });

        $this->renderList('Missing evidence', $data['missing_evidence'] ?? null, static function (array $note): string {
            $source = is_string($note['source'] ?? null) ? $note['source'] : 'source';
            $detail = is_string($note['detail'] ?? null) ? $note['detail'] : '';

            return sprintf('%s: %s', $source, $detail);
        });
    }

    /**
     * @param callable(array<array-key, mixed>): string $formatter
     */
    private function renderList(string $heading, mixed $items, callable $formatter): void
    {
        if (! is_array($items) || $items === []) {
            return;
        }

        $this->line('  <options=bold>' . OutputFormatter::escape($heading) . '</>');

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->line('  ' . OutputFormatter::escape($formatter($item)));
        }

        $this->newLine();
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:investigate could not build investigation context (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Investigation context is currently unavailable.',
            null,
            self::EXIT_FAILED,
            'failed',
        );
    }

    private function reject(string $message, ?string $hint, int $exitCode, string $error): int
    {
        if ($this->wantsJson()) {
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
