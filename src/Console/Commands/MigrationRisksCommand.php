<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Contracts\Authorizer;
use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidRiskQueryException;
use Beacon\Inspectors\MigrationRiskInspector;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:migration-risks')]
final class MigrationRisksCommand extends Command
{
    public const EXIT_DENIED = 1;

    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:migration-risks
        {--connection= : Laravel connection name. Defaults to the application default. Additional names must be configured.}
        {--migration= : Exact migration name}
        {--path= : Relative registered migration directory}
        {--category= : data_loss, locking, compatibility, existing_data, performance, foreign_key, or transaction}
        {--severity= : Minimum severity: critical, high, medium, low, or informational}
        {--pending : Analyze pending migrations. This is the default}
        {--include-executed : Also analyze migrations already recorded in the repository}
        {--page=1 : Page number, from 1 to 10000}
        {--limit=100 : Page size, from 1 to 100}
        {--json : Output the risk report as JSON on standard output}';

    protected $description = 'Analyze migration source for risk without executing it';

    protected $help = <<<'HELP'
        Prints the same report as the beacon.analyze_migration_risks MCP tool.

        Requires the "migration_risk" capability (BEACON_ALLOWED_CAPABILITIES). The command
        is read-only. It does not run migrations, migration PHP, or SQL. Pending migrations
        are analyzed by default. Pass --include-executed to include recorded migrations.
        Schema metadata is used only when the schema capability is also allowed.

        Findings are heuristic static analysis, not a guarantee of success, failure, locking, or data loss.

        Exit codes:
          0  the report was printed
          1  Beacon or the migration_risk capability does not allow this command, or a filter is invalid
          2  the report could not be collected
        HELP;

    public function handle(Beacon $beacon, Authorizer $authorizer): int
    {
        try {
            $data = $beacon->inspect(Principal::local(), MigrationRiskInspector::NAME, $this->parameters($authorizer))->toArray();
        } catch (AuthorizationException $exception) {
            return $this->reject(
                str_contains($exception->getMessage(), 'Beacon is disabled')
                    ? 'Beacon is disabled.'
                    : 'The migration_risk capability is not allowed.',
                'Set BEACON_ALLOWED_CAPABILITIES=migration_risk to allow it.',
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InspectorNotFoundException $exception) {
            $disabled = str_contains($exception->getMessage(), 'disabled');

            return $this->reject(
                $disabled ? 'The migration risk inspector is disabled.' : 'The migration risk inspector is not registered.',
                $disabled ? 'Enable it under "inspectors.migration_risk" in config/beacon.php.' : null,
                self::EXIT_DENIED,
                'not_allowed',
            );
        } catch (InvalidRiskQueryException $exception) {
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
    private function parameters(Authorizer $authorizer): array
    {
        $parameters = [
            'page' => $this->integerOption('page'),
            'limit' => $this->integerOption('limit'),
            'include_executed' => $this->option('include-executed') === true,
            'enrich' => $authorizer->decide(Principal::local(), InspectionCapability::Schema->capability())->allowed,
        ];

        if ($this->option('pending') === true) {
            $parameters['pending'] = true;
        }

        foreach (['connection' => 'connection', 'migration' => 'migration', 'path' => 'path', 'category' => 'category', 'severity' => 'severity'] as $option => $parameter) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters[$parameter] = $value;
            }
        }

        return $parameters;
    }

    private function integerOption(string $key): int
    {
        $value = $this->option($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1 && strlen($value) < 8) {
            return (int) $value;
        }

        throw InvalidRiskQueryException::type($key);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function render(array $data): void
    {
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $this->newLine();
        $this->line('  <options=bold>Laravel Beacon</>  Migration risks');
        $this->newLine();
        $this->components->twoColumnDetail('Analyzed', $this->count($summary['analyzed'] ?? null));
        $this->components->twoColumnDetail('Critical', $this->count($summary['critical'] ?? null));
        $this->components->twoColumnDetail('High', $this->count($summary['high'] ?? null));
        $this->components->twoColumnDetail('Medium', $this->count($summary['medium'] ?? null));
        $this->components->twoColumnDetail('Low', $this->count($summary['low'] ?? null));
        $this->components->twoColumnDetail('Informational', $this->count($summary['informational'] ?? null));
        $this->newLine();

        $findings = $data['findings'] ?? null;

        if (is_array($findings)) {
            foreach ($findings as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $label = $this->text($finding['severity'] ?? null) . ' ' . $this->text($finding['rule_id'] ?? null);
                $detail = $this->text($finding['title'] ?? null);
                $this->components->twoColumnDetail($label, $detail);
                $where = $this->text($finding['migration'] ?? null);

                if (is_int($finding['line'] ?? null)) {
                    $where .= ' line ' . $finding['line'];
                }

                $this->line('  <fg=gray>' . OutputFormatter::escape($where . ' ' . $this->text($finding['migration_status'] ?? null)) . '</>');
            }
        }

        $summaryText = $summary['summary'] ?? null;

        if (is_string($summaryText) && $summaryText !== '') {
            $this->newLine();
            $this->line('  <fg=gray>' . OutputFormatter::escape($summaryText) . '</>');
            $this->newLine();
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'unknown';
    }

    private function count(mixed $value): string
    {
        return is_int($value) ? (string) $value : 'unknown';
    }

    private function failed(\Throwable $exception): int
    {
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln(
                sprintf('beacon:migration-risks could not collect a risk report (%s).', $exception::class),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $this->reject(
            'Migration risk analysis is currently unavailable.',
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
