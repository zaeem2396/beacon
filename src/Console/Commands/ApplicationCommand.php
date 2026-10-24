<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Inspectors\ApplicationInspector;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:application')]
final class ApplicationCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:application
        {--json : Output the application information as JSON on standard output}';

    protected $description = 'Show safe metadata about this Laravel application and its PHP runtime';

    protected $help = <<<'HELP'
        Prints the same information as the beacon.get_application_info MCP tool.

        Requires the "application" capability (BEACON_ALLOWED_CAPABILITIES). The command is
        read-only. JSON output uses that tool's schema: every field is present, and null means
        the value is missing, invalid or could not be read.

        Exit codes:
          0  the information was printed
          1  Beacon or the application capability does not allow this command
          2  the information could not be collected
        HELP;

    public function handle(Beacon $beacon): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), ApplicationInspector::NAME)->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The application capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=application to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The application inspector is disabled.' : 'The application inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.application" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
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
        $this->line('  <options=bold>Laravel Beacon</>  Application');
        $this->newLine();

        foreach ($this->rows($data) as $label => $value) {
            $this->components->twoColumnDetail($label, '<fg=gray>' . OutputFormatter::escape($value) . '</>');
        }

        $this->newLine();
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    private function rows(array $data): array
    {
        return [
            'Name' => $this->text($data['name'] ?? null),
            'Environment' => $this->text($data['environment'] ?? null),
            'Debug' => $this->state($data['debug'] ?? null),
            'URL' => $this->text($data['url'] ?? null),
            'Timezone' => $this->text($data['timezone'] ?? null),
            'Locale' => $this->text($data['locale'] ?? null),
            'Fallback locale' => $this->text($data['fallback_locale'] ?? null),
            'Maintenance mode' => $this->state($data['maintenance_mode'] ?? null),
            'Laravel' => $this->text($data['laravel_version'] ?? null),
            'PHP' => $this->text($data['php_version'] ?? null),
            'Beacon' => $this->text($data['beacon_version'] ?? null),
        ];
    }

    private function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'unknown';
    }

    private function state(mixed $value): string
    {
        return match ($value) {
            true => 'on',
            false => 'off',
            default => 'unknown',
        };
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:application could not collect application information (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Application information is currently unavailable.',
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
