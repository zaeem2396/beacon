<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationCategory;
use Beacon\Data\ConfigurationSetting;
use Beacon\Data\ConfigurationState;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Reads the approved configuration settings from Laravel's effective repository.
 *
 * Named connections, stores, disks, mailers and channels are followed only
 * when the name is a dot-free identifier. Secret fields are recorded as
 * present or absent, never copied. The reader does not open a connection,
 * send mail, write a log, or mutate configuration.
 */
final class ConfigurationReader
{
    /**
     * @var list<string>
     */
    private const CIPHERS = ['AES-256-CBC', 'AES-128-CBC'];

    /**
     * @var list<string>
     */
    private const MAINTENANCE = ['cache', 'file'];

    /**
     * @var list<string>
     */
    private const DATABASE_DRIVERS = ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv'];

    /**
     * @var list<string>
     */
    private const CACHE_DRIVERS = ['apc', 'array', 'database', 'dynamodb', 'failover', 'file', 'memcached', 'null', 'octane', 'redis', 'session', 'storage'];

    /**
     * @var list<string>
     */
    private const QUEUE_DRIVERS = ['background', 'beanstalkd', 'database', 'deferred', 'failover', 'null', 'redis', 'sqs', 'sync'];

    /**
     * @var list<string>
     */
    private const SESSION_DRIVERS = ['apc', 'array', 'cookie', 'database', 'dynamodb', 'file', 'memcached', 'native', 'null', 'redis'];

    /**
     * @var list<string>
     */
    private const MAIL_TRANSPORTS = ['array', 'cloudflare', 'failover', 'log', 'mail', 'mailgun', 'postmark', 'resend', 'roundrobin', 'sendmail', 'ses', 'ses-v2', 'smtp'];

    /**
     * @var list<string>
     */
    private const MAIL_SCHEMES = ['smtp', 'smtps', 'ssl', 'tls'];

    /**
     * @var list<string>
     */
    private const FILESYSTEM_DRIVERS = ['ftp', 'local', 'read-through', 's3', 'scoped', 'sftp'];

    /**
     * @var list<string>
     */
    private const VISIBILITY = ['private', 'public'];

    /**
     * @var list<string>
     */
    private const LOGGING_DRIVERS = ['custom', 'daily', 'errorlog', 'monolog', 'monthly', 'single', 'slack', 'stack', 'syslog'];

    /**
     * @var list<string>
     */
    private const LEVELS = ['alert', 'critical', 'debug', 'emergency', 'error', 'info', 'notice', 'warning'];

    /**
     * @var list<string>
     */
    private const SAME_SITE = ['lax', 'none', 'strict'];

    /**
     * @var list<string>
     */
    private const SENSITIVE_FRAGMENTS = ['password', 'passwd', 'secret', 'token', 'credential', 'privatekey', 'apikey'];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {
    }

    public function read(): ConfigurationCatalog
    {
        $environment = $this->app->environment();
        $settings = array_merge(
            $this->application(),
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->session(),
            $this->mail(),
            $this->filesystem(),
            $this->logging(),
        );

        $ids = [];

        foreach ($settings as $setting) {
            $ids[] = $setting->id;
        }

        if ($ids !== ConfigurationCatalog::IDS) {
            throw new \RuntimeException('Configuration settings were not collected in the approved order.');
        }

        return new ConfigurationCatalog(is_string($environment) ? $environment : '', $this->urlScheme(), $settings);
    }

    /**
     * @return list<ConfigurationSetting>
     */
    private function application(): array
    {
        return [
            $this->enumeration(ConfigurationCategory::Application, 'app.cipher', $this->config->get('app.cipher'), self::CIPHERS, false),
            $this->enumeration(ConfigurationCategory::Application, 'app.maintenance_driver', $this->config->get('app.maintenance.driver'), self::MAINTENANCE),
            $this->applicationKey(),
        ];
    }

    /**
     * @return list<ConfigurationSetting>
     */
    private function database(): array
    {
        $named = $this->named(ConfigurationCategory::Database, 'database.default', 'database.connections');

        return [
            $named['name'],
            $this->registered(ConfigurationCategory::Database, 'database.connection', $named),
            $this->driver(ConfigurationCategory::Database, 'database.driver', $named, self::DATABASE_DRIVERS),
            $this->secret(ConfigurationCategory::Database, 'database.password', $named, 'password'),
            $this->secret(ConfigurationCategory::Database, 'database.url', $named, 'url'),
        ];
    }

    /**
     * @return list<ConfigurationSetting>
     */
    private function cache(): array
    {
        $named = $this->named(ConfigurationCategory::Cache, 'cache.default', 'cache.stores');

        return [
            $named['name'],
            $this->registered(ConfigurationCategory::Cache, 'cache.store', $named),
            $this->driver(ConfigurationCategory::Cache, 'cache.driver', $named, self::CACHE_DRIVERS),
        ];
    }

    /**
     * @return list<ConfigurationSetting>
     */
    private function queue(): array
    {
        $named = $this->named(ConfigurationCategory::Queue, 'queue.default', 'queue.connections');

        return [
            $named['name'],
            $this->registered(ConfigurationCategory::Queue, 'queue.connection', $named),
            $this->driver(ConfigurationCategory::Queue, 'queue.driver', $named, self::QUEUE_DRIVERS),
            $this->queueName($named),
        ];
    }

    /**
     * @return list<ConfigurationSetting>
     */
    private function session(): array
    {
        return [
            $this->enumeration(ConfigurationCategory::Session, 'session.driver', $this->config->get('session.driver'), self::SESSION_DRIVERS),
            $this->flag('session.secure', 'session.secure'),
            $this->flag('session.http_only', 'session.http_only'),
            $this->enumeration(ConfigurationCategory::Session, 'session.same_site', $this->config->get('session.same_site'), self::SAME_SITE),
            $this->flag('session.encrypt', 'session.encrypt'),
            $this->identifier(ConfigurationCategory::Session, 'session.cookie', $this->config->get('session.cookie'), false),
        ];
    }

    /**
     * @return list<ConfigurationSetting>
     */
    private function mail(): array
    {
        $named = $this->named(ConfigurationCategory::Mail, 'mail.default', 'mail.mailers');

        return [
            $named['name'],
            $this->registered(ConfigurationCategory::Mail, 'mail.mailer', $named),
            $this->transport($named),
            $this->mailScheme($named),
            $this->secret(ConfigurationCategory::Mail, 'mail.username', $named, 'username'),
            $this->secret(ConfigurationCategory::Mail, 'mail.password', $named, 'password'),
        ];
    }

    /**
     * @return list<ConfigurationSetting>
     */
    private function filesystem(): array
    {
        $named = $this->named(ConfigurationCategory::Filesystem, 'filesystem.default', 'filesystems.disks');

        return [
            $named['name'],
            $this->registered(ConfigurationCategory::Filesystem, 'filesystem.disk', $named),
            $this->driver(ConfigurationCategory::Filesystem, 'filesystem.driver', $named, self::FILESYSTEM_DRIVERS),
            $this->visibility($named),
            $this->credentials($named),
        ];
    }

    /**
     * @return list<ConfigurationSetting>
     */
    private function logging(): array
    {
        $named = $this->named(ConfigurationCategory::Logging, 'logging.default', 'logging.channels');

        return [
            $named['name'],
            $this->registered(ConfigurationCategory::Logging, 'logging.channel', $named),
            $this->driver(ConfigurationCategory::Logging, 'logging.driver', $named, self::LOGGING_DRIVERS),
            $this->level($named),
            $this->handler($named),
            $this->secret(ConfigurationCategory::Logging, 'logging.webhook', $named, 'url'),
        ];
    }

    private function applicationKey(): ConfigurationSetting
    {
        $value = $this->config->get('app.key');

        if ($value === null || $value === '') {
            return $this->fact(ConfigurationCategory::Application, 'app.key', ConfigurationState::Missing, null);
        }

        if (! is_string($value)) {
            return $this->fact(ConfigurationCategory::Application, 'app.key', ConfigurationState::Invalid, null);
        }

        return $this->fact(ConfigurationCategory::Application, 'app.key', ConfigurationState::Sensitive, null);
    }

    /**
     * @return array{name: ConfigurationSetting, definition: mixed, followed: bool}
     */
    private function named(ConfigurationCategory $category, string $id, string $prefix): array
    {
        $raw = $this->config->get($this->defaultKey($id));
        $followed = false;
        $definition = null;

        if (is_string($raw) && $raw !== '' && $this->isIdentifier($raw)) {
            $definition = $this->config->get($prefix . '.' . $raw);
            $followed = true;
        }

        $withhold = $followed && is_string($raw) && $this->namesSecret($raw, $definition);

        return [
            'name' => $this->identifier($category, $id, $raw, $withhold),
            'definition' => $followed ? $definition : null,
            'followed' => $followed,
        ];
    }

    private function defaultKey(string $id): string
    {
        return match ($id) {
            'database.default' => 'database.default',
            'cache.default' => 'cache.default',
            'queue.default' => 'queue.default',
            'mail.default' => 'mail.default',
            'filesystem.default' => 'filesystems.default',
            'logging.default' => 'logging.default',
            default => throw new \LogicException('Configuration reader was asked for an unknown default.'),
        };
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function registered(ConfigurationCategory $category, string $id, array $named): ConfigurationSetting
    {
        if ($named['followed'] !== true) {
            return $this->fact($category, $id, $this->unresolved($named['name']), null);
        }

        if (! is_array($named['definition'])) {
            return $this->fact($category, $id, ConfigurationState::Invalid, null);
        }

        return $this->fact($category, $id, ConfigurationState::Present, 'registered');
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     * @param list<string> $allowed
     */
    private function driver(ConfigurationCategory $category, string $id, array $named, array $allowed): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact($category, $id, $this->unresolved($named['name']), null);
        }

        return $this->enumeration($category, $id, $named['definition']['driver'] ?? null, $allowed);
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function transport(array $named): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact(ConfigurationCategory::Mail, 'mail.transport', $this->unresolved($named['name']), null);
        }

        return $this->enumeration(ConfigurationCategory::Mail, 'mail.transport', $named['definition']['transport'] ?? null, self::MAIL_TRANSPORTS);
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function secret(ConfigurationCategory $category, string $id, array $named, string $field): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact($category, $id, $this->unresolved($named['name']), null);
        }

        $value = $named['definition'][$field] ?? null;

        if ($value === null || $value === '') {
            return $this->fact($category, $id, ConfigurationState::Missing, null);
        }

        if (! is_string($value)) {
            return $this->fact($category, $id, ConfigurationState::Invalid, null);
        }

        return $this->fact($category, $id, ConfigurationState::Sensitive, null);
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function mailScheme(array $named): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact(ConfigurationCategory::Mail, 'mail.scheme', $this->unresolved($named['name']), null);
        }

        $scheme = $named['definition']['scheme'] ?? null;

        if ($scheme === null || $scheme === '') {
            $scheme = $named['definition']['encryption'] ?? null;
        }

        return $this->enumeration(ConfigurationCategory::Mail, 'mail.scheme', $scheme, self::MAIL_SCHEMES);
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function queueName(array $named): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact(ConfigurationCategory::Queue, 'queue.name', $this->unresolved($named['name']), null);
        }

        $name = $named['definition']['queue'] ?? null;

        if ($name === null || $name === '') {
            return $this->fact(ConfigurationCategory::Queue, 'queue.name', ConfigurationState::Missing, null);
        }

        if (! is_string($name)) {
            return $this->fact(ConfigurationCategory::Queue, 'queue.name', ConfigurationState::Invalid, null);
        }

        if (str_contains($name, '://') || str_contains($name, '@') || str_contains(strtolower($name), 'arn:')) {
            return $this->fact(ConfigurationCategory::Queue, 'queue.name', ConfigurationState::Sensitive, null);
        }

        if (! $this->isIdentifier($name) || $this->containsSensitiveFragment($name)) {
            return $this->fact(ConfigurationCategory::Queue, 'queue.name', ConfigurationState::Invalid, null);
        }

        return $this->fact(ConfigurationCategory::Queue, 'queue.name', ConfigurationState::Present, $name);
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function visibility(array $named): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact(ConfigurationCategory::Filesystem, 'filesystem.visibility', $this->unresolved($named['name']), null);
        }

        return $this->enumeration(ConfigurationCategory::Filesystem, 'filesystem.visibility', $named['definition']['visibility'] ?? null, self::VISIBILITY);
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function credentials(array $named): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact(ConfigurationCategory::Filesystem, 'filesystem.credentials', $this->unresolved($named['name']), null);
        }

        $invalid = false;

        foreach (['key', 'secret', 'password'] as $field) {
            $value = $named['definition'][$field] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value)) {
                return $this->fact(ConfigurationCategory::Filesystem, 'filesystem.credentials', ConfigurationState::Sensitive, null);
            }

            $invalid = true;
        }

        return $this->fact(
            ConfigurationCategory::Filesystem,
            'filesystem.credentials',
            $invalid ? ConfigurationState::Invalid : ConfigurationState::Missing,
            null,
        );
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function level(array $named): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact(ConfigurationCategory::Logging, 'logging.level', $this->unresolved($named['name']), null);
        }

        return $this->enumeration(ConfigurationCategory::Logging, 'logging.level', $named['definition']['level'] ?? null, self::LEVELS);
    }

    /**
     * @param array{name: ConfigurationSetting, definition: mixed, followed: bool} $named
     */
    private function handler(array $named): ConfigurationSetting
    {
        if ($named['followed'] !== true || ! is_array($named['definition'])) {
            return $this->fact(ConfigurationCategory::Logging, 'logging.handler', $this->unresolved($named['name']), null);
        }

        $driver = $named['definition']['driver'] ?? null;

        if (! is_string($driver) || strtolower($driver) !== 'monolog') {
            return $this->fact(ConfigurationCategory::Logging, 'logging.handler', ConfigurationState::Missing, null);
        }

        $handler = $named['definition']['handler'] ?? null;

        if ($handler === null || $handler === '') {
            return $this->fact(ConfigurationCategory::Logging, 'logging.handler', ConfigurationState::Missing, null);
        }

        if (! is_string($handler) || ! $this->isHandlerClass($handler)) {
            return $this->fact(ConfigurationCategory::Logging, 'logging.handler', ConfigurationState::Sensitive, null);
        }

        return $this->fact(ConfigurationCategory::Logging, 'logging.handler', ConfigurationState::Present, $handler);
    }

    private function flag(string $id, string $key): ConfigurationSetting
    {
        $value = $this->config->get($key);

        if ($value === null || $value === '') {
            return $this->fact(ConfigurationCategory::Session, $id, ConfigurationState::Missing, null);
        }

        if (! is_bool($value)) {
            return $this->fact(ConfigurationCategory::Session, $id, ConfigurationState::Invalid, null);
        }

        return $this->fact(ConfigurationCategory::Session, $id, ConfigurationState::Present, $value);
    }

    /**
     * @param list<string> $allowed
     */
    private function enumeration(
        ConfigurationCategory $category,
        string $id,
        mixed $value,
        array $allowed,
        bool $lowercase = true,
    ): ConfigurationSetting {
        if ($value === null || $value === '') {
            return $this->fact($category, $id, ConfigurationState::Missing, null);
        }

        if (! is_string($value)) {
            return $this->fact($category, $id, ConfigurationState::Invalid, null);
        }

        $canonical = $lowercase ? strtolower($value) : $value;

        if (! in_array($canonical, $allowed, true)) {
            return $this->fact($category, $id, ConfigurationState::Invalid, null);
        }

        return $this->fact($category, $id, ConfigurationState::Present, $canonical);
    }

    private function identifier(ConfigurationCategory $category, string $id, mixed $value, bool $withhold): ConfigurationSetting
    {
        if ($value === null || $value === '') {
            return $this->fact($category, $id, ConfigurationState::Missing, null);
        }

        if (! is_string($value)) {
            return $this->fact($category, $id, ConfigurationState::Invalid, null);
        }

        if ($withhold || ! $this->isIdentifier($value) || $this->containsSensitiveFragment($value)) {
            return $this->fact($category, $id, ConfigurationState::Sensitive, null);
        }

        return $this->fact($category, $id, ConfigurationState::Present, $value);
    }

    private function unresolved(ConfigurationSetting $name): ConfigurationState
    {
        return $name->state === ConfigurationState::Missing ? ConfigurationState::Missing : ConfigurationState::Invalid;
    }

    private function fact(ConfigurationCategory $category, string $id, ConfigurationState $state, bool|string|null $value): ConfigurationSetting
    {
        return new ConfigurationSetting($category, $id, $state, $value);
    }

    private function urlScheme(): ?string
    {
        $url = $this->config->get('app.url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === 'http' || $scheme === 'https') {
            return $scheme;
        }

        return null;
    }

    private function namesSecret(string $name, mixed $definition): bool
    {
        if (! is_array($definition)) {
            return false;
        }

        foreach (['password', 'username', 'key', 'secret', 'url'] as $field) {
            $secret = $definition[$field] ?? null;

            if (is_string($secret) && $secret !== '' && $secret === $name) {
                return true;
            }
        }

        return false;
    }

    private function isIdentifier(string $value): bool
    {
        $length = strlen($value);

        if ($length < 1 || $length > 64) {
            return false;
        }

        if (! str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $value[0])) {
            return false;
        }

        for ($index = 1; $index < $length; $index++) {
            if (! str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', $value[$index])) {
                return false;
            }
        }

        return true;
    }

    private function isHandlerClass(string $value): bool
    {
        $length = strlen($value);

        if ($length < 8 || $length > 120 || ! str_contains($value, '\\') || ! str_ends_with($value, 'Handler')) {
            return false;
        }

        $segments = explode('\\', $value);

        if (count($segments) < 2) {
            return false;
        }

        foreach ($segments as $segment) {
            $segmentLength = strlen($segment);

            if ($segmentLength < 1 || ! str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZ', $segment[0])) {
                return false;
            }

            for ($index = 1; $index < $segmentLength; $index++) {
                if (! str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_', $segment[$index])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function containsSensitiveFragment(string $value): bool
    {
        $compact = '';
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if (str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $character)) {
                $compact .= strtolower($character);
            }
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($compact, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
