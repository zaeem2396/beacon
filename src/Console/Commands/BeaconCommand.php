<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\Core\BeaconConfig;
use Beacon\Core\Version;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'beacon')]
final class BeaconCommand extends Command
{
    protected $signature = 'beacon';

    protected $description = 'Show the Laravel Beacon version, status and available commands';

    protected $help = 'Laravel Beacon exposes safe, read-only, redacted information about this application to AI agents over MCP.';

    public function handle(BeaconConfig $config): int
    {
        $this->newLine();
        $this->line(sprintf('  <options=bold>Laravel Beacon</> <fg=gray>%s</>', Version::current()));
        $this->line('  Read-only, redacted application insight for AI agents over MCP.');
        $this->newLine();

        $this->components->twoColumnDetail('Beacon', $config->enabled() ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('MCP', $config->mcpAccess()->allowed ? 'available' : 'unavailable');
        $this->newLine();

        $this->components->info('Available commands');

        foreach ($this->beaconCommands() as $name => $description) {
            $this->components->twoColumnDetail("<fg=green>{$name}</>", $description);
        }

        $this->newLine();
        $this->line('  Run <fg=green>php artisan beacon:check</> to diagnose your installation.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function beaconCommands(): array
    {
        $commands = [$this->getName() ?? 'beacon' => $this->getDescription()];

        foreach ($this->getApplication()?->all('beacon') ?? [] as $name => $command) {
            if (! $command->isHidden()) {
                $commands[$name] = $command->getDescription();
            }
        }

        ksort($commands);

        return $commands;
    }
}
