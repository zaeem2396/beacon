<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidHealthQueryException;
use Beacon\Inspectors\HealthInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:health')]
final class HealthCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:health
        {--json : Output the health report as JSON on standard output}';

    protected $description = 'Show a read-only health report for this Laravel application';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.get_health MCP tool.

        Requires the "health" capability (BEACON_ALLOWED_CAPABILITIES). Database, cache,
        and queue probes stay off unless beacon.health enables them. The command is
        read-only and does not accept check names. Exit 0 means the report was printed.
        The health status is in the output, including when a check is critical.

        Exit codes:
          0  the report was printed
          1  Beacon or the health capability does not allow this command
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), HealthInspector::NAME)->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The health capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=health to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The health inspector is disabled.' : 'The health inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.health" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidHealthQueryException $exception) {
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
        $this->line('  <options=bold>Laravel Beacon</>  Health');
        $this->newLine();
        $this->components->twoColumnDetail('Overall', $this->status($data['status'] ?? null));
        $this->components->twoColumnDetail('Summary', $this->text($data['summary'] ?? null));
        $this->components->twoColumnDetail('Assessed', $this->text($data['assessed_at'] ?? null));
        $this->newLine();

        $checks = $data['checks'] ?? null;

        if (! is_array($checks)) {
            return;
        }

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $name = $this->text($check['name'] ?? null);
            $detail = $this->status($check['status'] ?? null);

            if (is_int($check['duration_ms'] ?? null)) {
                $detail .= '  ' . $check['duration_ms'] . ' ms';
            }

            $this->components->twoColumnDetail($name, $detail);
            $summary = $check['summary'] ?? null;

            if (is_string($summary) && $summary !== '') {
                $this->line('  <fg=gray>' . OutputFormatter::escape($summary) . '</>');
            }
        }

        $this->newLine();
    }

    private function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'unknown';
    }

    private function status(mixed $value): string
    {
        $label = $this->text($value);

        return match ($label) {
            'healthy' => '<fg=green>healthy</>',
            'warning' => '<fg=yellow>warning</>',
            'critical' => '<fg=red>critical</>',
            'skipped' => '<fg=gray>skipped</>',
            default => '<fg=gray>unknown</>',
        };
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:health could not collect a health report (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Health inspection is currently unavailable.',
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
