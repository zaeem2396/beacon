<?php

declare(strict_types=1);

namespace Beacon\Console\Commands;

use Beacon\BeaconServiceProvider;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;

#[AsCommand(name: 'beacon:install')]
final class InstallCommand extends Command
{
    protected $signature = 'beacon:install';

    protected $description = 'Publish the Beacon configuration and show the next setup steps';

    protected $help = <<<'HELP'
        Publishes config/beacon.php if it does not exist yet. An existing file is never overwritten.
        The command does not edit .env, generate tokens or enable MCP; it only explains how to do so.
        To replace an existing configuration file deliberately, run:

          php artisan vendor:publish --tag=beacon-config --force
        HELP;

    public function handle(): int
    {
        $path = $this->laravel->configPath('beacon.php');

        if (file_exists($path)) {
            $this->components->info('config/beacon.php already exists and was left unchanged.');
        } else {
            $this->callSilently('vendor:publish', ['--tag' => BeaconServiceProvider::CONFIG_TAG]);

            if (! file_exists($path)) {
                $this->components->error('config/beacon.php could not be published. Check that the config directory is writable.');

                return self::FAILURE;
            }

            $this->components->info('Published config/beacon.php.');
        }

        $this->components->info('Beacon is installed. MCP stays disabled until you enable it.');

        $this->line('  To enable MCP, add the following to your <fg=yellow>.env</> file:');
        $this->newLine();
        $this->line('    BEACON_MCP_ENABLED=true');
        $this->line('    BEACON_MCP_TOKEN=' . OutputFormatter::escape('<random token of at least 32 characters>'));
        $this->line('    BEACON_MCP_ENVIRONMENTS=local');
        $this->line('    BEACON_ALLOWED_CAPABILITIES=' . OutputFormatter::escape('<capabilities to allow, e.g. application,routes,health,database>'));
        $this->newLine();
        $this->line('  Generate a token (keep it out of version control):');
        $this->newLine();
        $this->line('    php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"');
        $this->newLine();

        if (! class_exists(\Laravel\Mcp\Server::class)) {
            $this->line('  MCP also requires laravel/mcp: <fg=green>composer require laravel/mcp</>');
            $this->newLine();
        }

        $this->line('  Remote (HTTP) access is a separate opt-in; see the README before enabling it.');
        $this->line('  Inspect this application with <fg=green>php artisan beacon:application</>, <fg=green>php artisan beacon:health</>, or <fg=green>php artisan beacon:database</> after allowing those capabilities.');
        $this->line('  Then run <fg=green>php artisan beacon:check</> to verify your setup.');
        $this->newLine();

        return self::SUCCESS;
    }
}
