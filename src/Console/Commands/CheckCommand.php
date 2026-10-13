<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\Version;
use Beacon\Diagnostics\DiagnosticReport;
use Beacon\Diagnostics\Diagnostics;
use Beacon\Diagnostics\DiagnosticStatus;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'beacon:check')]
final class CheckCommand extends Command
{
    protected $signature = 'beacon:check
        {--json : Output the report as JSON on standard output}';

    protected $description = 'Diagnose the Beacon installation and configuration';

    protected $help = <<<'HELP'
        Runs read-only diagnostics. No external services are contacted and no state is changed.

        Exit codes:
          0  every check passed, or is disabled, not implemented or a warning
          1  at least one check is misconfigured
          2  at least one check failed, or the diagnostics could not run
        HELP;

    public function handle(): int
    {
        try {
            $report = $this->laravel->make(Diagnostics::class)->run();
        } catch (\Throwable $exception) {
            return $this->failed($exception);
        }

        if ($this->option('json')) {
            $this->writeJson($report->toArray());
        } else {
            $this->render($report);
        }

        return $report->exitCode();
    }

    private function render(DiagnosticReport $report): void
    {
        $this->newLine();
        $this->line(sprintf('  <options=bold>Laravel Beacon</> <fg=gray>%s</>', Version::current()));
        $this->newLine();

        foreach ($report->checks as $check) {
            $this->components->twoColumnDetail(OutputFormatter::escape($check->name), $this->label($check->status));
            $this->line('  <fg=gray>' . OutputFormatter::escape($check->message) . '</>');

            if ($check->hint !== null && $check->status !== DiagnosticStatus::Pass) {
                $this->line('  <fg=gray>→ ' . OutputFormatter::escape($check->hint) . '</>');
            }
        }

        $this->newLine();

        match ($report->status()) {
            DiagnosticReport::STATUS_OK => $this->components->info('Beacon is correctly configured.'),
            DiagnosticReport::STATUS_WARN => $this->components->warn('Beacon works, but some checks need review.'),
            DiagnosticReport::STATUS_MISCONFIGURED => $this->components->error('Beacon is misconfigured. Fix the checks above.'),
            default => $this->components->error('Some checks failed.'),
        };
    }

    private function label(DiagnosticStatus $status): string
    {
        return match ($status) {
            DiagnosticStatus::Pass => '<fg=green;options=bold>PASS</>',
            DiagnosticStatus::Warn => '<fg=yellow;options=bold>WARN</>',
            DiagnosticStatus::Disabled => '<fg=gray;options=bold>DISABLED</>',
            DiagnosticStatus::NotImplemented => '<fg=gray;options=bold>NOT IMPLEMENTED</>',
            DiagnosticStatus::Misconfigured => '<fg=red;options=bold>MISCONFIGURED</>',
            DiagnosticStatus::Fail => '<fg=red;options=bold>FAIL</>',
        };
    }

    private function failed(\Throwable $exception): int
    {
        $message = sprintf('beacon:check could not run (%s).', $exception::class);

        if (! $this->option('json')) {
            $this->components->error($message);

            return DiagnosticReport::EXIT_FAILED;
        }

        // Only when stderr is a separate stream, so JSON on stdout stays valid.
        if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln($message, OutputInterface::OUTPUT_RAW);
        }

        $this->writeJson([
            'schema_version' => DiagnosticReport::SCHEMA_VERSION,
            'beacon_version' => Version::current(),
            'status' => DiagnosticReport::STATUS_FAIL,
            'exit_code' => DiagnosticReport::EXIT_FAILED,
            'summary' => array_fill_keys(array_column(DiagnosticStatus::cases(), 'value'), 0),
            'checks' => [],
        ]);

        return DiagnosticReport::EXIT_FAILED;
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
