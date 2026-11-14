<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\Version;
use Beacon\Data\LaravelRuntime;
use Beacon\Data\PhpRuntime;
use Beacon\Data\RuntimeDrivers;
use Beacon\Data\RuntimeInspection;
use Beacon\Health\SafeName;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Collects allowlisted PHP and Laravel runtime facts for the current process.
 *
 * @implements Inspector<RuntimeInspection>
 */
final class RuntimeInspector implements Inspector
{
    public const NAME = 'runtime';

    /**
     * @var list<string>
     */
    private const EXTENSIONS = [
        'curl',
        'intl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_mysql',
        'pdo_pgsql',
        'pdo_sqlite',
        'redis',
        'sodium',
        'zip',
        'zlib',
    ];

    /**
     * @var list<string>
     */
    private const DRIVERS = [
        'array',
        'beanstalkd',
        'database',
        'deferred',
        'dynamodb',
        'failover',
        'file',
        'log',
        'memcached',
        'null',
        'redis',
        's3',
        'session',
        'sqs',
        'stack',
        'sync',
        'syslog',
        'errorlog',
        'monolog',
        'papertrail',
        'stderr',
        'single',
        'daily',
        'slack',
        'mysql',
        'mariadb',
        'pgsql',
        'sqlite',
        'sqlsrv',
        'cookie',
        'apc',
        'apcu',
        'octane',
        'background',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Runtime->capability();
    }

    /**
     * The runtime inspector takes no parameters.
     *
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        if ($parameters !== []) {
            throw new \Beacon\Exceptions\InvalidRuntimeQueryException('Runtime inspection does not accept arguments.');
        }

        $beacon = Version::current();

        return new RuntimeInspection(
            php: new PhpRuntime(
                version: PHP_VERSION,
                sapi: PHP_SAPI,
                os: $this->os(),
                memoryLimit: $this->ini('memory_limit'),
                maxExecutionTime: $this->ini('max_execution_time'),
                uploadMaxFilesize: $this->ini('upload_max_filesize'),
                postMaxSize: $this->ini('post_max_size'),
                extensions: $this->extensions(),
            ),
            laravel: new LaravelRuntime(
                version: $this->app->version(),
                environment: $this->app->environment(),
                debug: $this->bool('app.debug'),
                timezone: $this->timezone(),
                locale: $this->locale(),
                booted: $this->app->isBooted(),
                maintenanceMode: $this->maintenance(),
            ),
            drivers: new RuntimeDrivers(
                cache: $this->storeDriver('cache.default', 'cache.stores'),
                session: $this->driver('session.driver'),
                queue: $this->storeDriver('queue.default', 'queue.connections'),
                filesystem: $this->storeDriver('filesystems.default', 'filesystems.disks'),
                loggingDefault: $this->name('logging.default'),
                loggingChannel: $this->loggingChannel(),
                databaseConnection: $this->name('database.default'),
                databaseDriver: $this->storeDriver('database.default', 'database.connections'),
            ),
            summary: 'Configured drivers are reported. Backend reachability was not probed.',
            beaconVersion: $beacon === Version::UNKNOWN ? null : $beacon,
        );
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        $present = [];

        foreach (self::EXTENSIONS as $extension) {
            if (extension_loaded($extension)) {
                $present[] = $extension;
            }
        }

        return $present;
    }

    private function os(): string
    {
        return PHP_OS_FAMILY;
    }

    private function ini(string $option): ?string
    {
        $value = ini_get($option);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._+-]{1,32}$/', $value) === 1 ? $value : null;
    }

    private function bool(string $key): ?bool
    {
        $value = $this->config->get($key);

        return is_bool($value) ? $value : null;
    }

    private function timezone(): ?string
    {
        $timezone = $this->config->get('app.timezone');

        if (! is_string($timezone) || trim($timezone) === '') {
            return null;
        }

        try {
            return (new \DateTimeZone(trim($timezone)))->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    private function locale(): ?string
    {
        $locale = $this->config->get('app.locale');

        return is_string($locale) && preg_match('/^[A-Za-z]{2,8}([_-][A-Za-z0-9]{1,8}){0,3}$/', $locale) === 1
            ? $locale
            : null;
    }

    private function maintenance(): ?bool
    {
        try {
            return $this->app->isDownForMaintenance();
        } catch (\Throwable) {
            return null;
        }
    }

    private function name(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && SafeName::isIdentifier($value) ? $value : null;
    }

    private function driver(string $key): ?string
    {
        $value = $this->config->get($key);

        if (! is_string($value)) {
            return null;
        }

        $canonical = strtolower($value);

        return in_array($canonical, self::DRIVERS, true) ? $canonical : null;
    }

    private function storeDriver(string $defaultKey, string $groupKey): ?string
    {
        $name = $this->name($defaultKey);

        if ($name === null) {
            return null;
        }

        $definition = $this->config->get($groupKey . '.' . $name);

        if (! is_array($definition)) {
            return null;
        }

        $driver = $definition['driver'] ?? null;

        if (! is_string($driver)) {
            return null;
        }

        $canonical = strtolower($driver);

        return in_array($canonical, self::DRIVERS, true) ? $canonical : null;
    }

    private function loggingChannel(): ?string
    {
        $default = $this->name('logging.default');

        if ($default === null) {
            return null;
        }

        $channel = $this->config->get('logging.channels.' . $default);

        if (! is_array($channel)) {
            return null;
        }

        $driver = $channel['driver'] ?? null;

        if (! is_string($driver)) {
            return null;
        }

        $canonical = strtolower($driver);

        return in_array($canonical, self::DRIVERS, true) ? $canonical : null;
    }
}
