<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\BeaconConfig;
use Beacon\Requests\RequestStore;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Maintenance cleanup for Beacon request storage.
 *
 * Does not require the requests capability. Deletes only rows in the Beacon
 * beacon_requests table according to beacon.requests.retention_hours.
 */
#[AsCommand(name: 'beacon:requests-prune')]
final class RequestsPruneCommand extends Command
{
    public const EXIT_FAILED = 2;

    protected $signature = 'beacon:requests-prune
        {--json : Output the prune result as JSON on standard output}';

    protected $description = 'Delete Beacon request observations older than the configured retention';

    protected $help = <<<'HELP'
        Deletes old rows from Beacon request storage using beacon.requests.retention_hours
        (default 168, bounds 1 to 720). This is a maintenance command for Beacon's own
        diagnostics table. It does not require the requests MCP capability and does not
        modify application business tables.

        Exit codes:
          0  prune completed
          2  request storage was unavailable or prune failed
        HELP;

    public function handle(RequestStore $store, BeaconConfig $config): int
    {
        $hours = $config->requestRetentionHours();

        if (! $store->available()) {
            return $this->reject(
                'Request storage is unavailable.',
                'Publish and run the Beacon requests migration before pruning.',
            );
        }

        try {
            $deleted = $store->prune($hours);
        } catch (\Throwable $exception) {
            if ($this->output->getOutput() instanceof ConsoleOutputInterface) {
                $this->output->getErrorStyle()->writeln(
                    sprintf('beacon:requests-prune could not prune (%s).', $exception::class),
                    OutputInterface::OUTPUT_RAW,
                );
            }

            return $this->reject('Request prune is currently unavailable.', null);
        }

        $payload = [
            'deleted' => $deleted,
            'retention_hours' => $hours,
            'summary' => sprintf('Deleted %d request observation(s) older than %d hour(s).', $deleted, $hours),
        ];

        if ($this->option('json')) {
            $this->writeJson($payload);
        } else {
            $this->components->info($payload['summary']);
        }

        return self::SUCCESS;
    }

    private function reject(string $message, ?string $hint): int
    {
        if ($this->option('json')) {
            $payload = ['error' => 'failed', 'message' => $message];

            if ($hint !== null) {
                $payload['hint'] = $hint;
            }

            $this->writeJson($payload);

            return self::EXIT_FAILED;
        }

        $this->components->error($message);

        if ($hint !== null) {
            $this->line('  <fg=gray>' . OutputFormatter::escape($hint) . '</>');
        }

        return self::EXIT_FAILED;
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
