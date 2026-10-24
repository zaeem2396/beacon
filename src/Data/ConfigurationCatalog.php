<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * The approved configuration facts an analyzer is allowed to see.
 *
 * The catalog is always the full allowlist, in a fixed order. Filtering
 * happens later, when a page is built. url_scheme is only http, https, or
 * null. The original URL is not stored.
 */
final readonly class ConfigurationCatalog extends DataObject
{
    /**
     * Approved setting ids, in response order.
     *
     * @var list<string>
     */
    public const IDS = [
        'app.cipher',
        'app.maintenance_driver',
        'app.key',
        'database.default',
        'database.connection',
        'database.driver',
        'database.password',
        'database.url',
        'cache.default',
        'cache.store',
        'cache.driver',
        'queue.default',
        'queue.connection',
        'queue.driver',
        'queue.name',
        'session.driver',
        'session.secure',
        'session.http_only',
        'session.same_site',
        'session.encrypt',
        'session.cookie',
        'mail.default',
        'mail.mailer',
        'mail.transport',
        'mail.scheme',
        'mail.username',
        'mail.password',
        'filesystem.default',
        'filesystem.disk',
        'filesystem.driver',
        'filesystem.visibility',
        'filesystem.credentials',
        'logging.default',
        'logging.channel',
        'logging.driver',
        'logging.level',
        'logging.handler',
        'logging.webhook',
    ];

    /**
     * @var array<string, ConfigurationSetting>
     */
    private array $index;

    /**
     * @param list<ConfigurationSetting> $settings
     */
    public function __construct(
        public string $environment,
        public ?string $urlScheme,
        public array $settings,
    ) {
        if ($urlScheme !== null && $urlScheme !== 'http' && $urlScheme !== 'https') {
            throw new \InvalidArgumentException('The URL scheme must be http, https, or null.');
        }

        $index = [];

        foreach ($settings as $setting) {
            if (! $setting instanceof ConfigurationSetting) {
                throw new \InvalidArgumentException('Configuration catalog settings must be configuration settings.');
            }

            if (self::categoryFor($setting->id) !== $setting->category) {
                throw new \InvalidArgumentException('Configuration setting category does not match its id.');
            }

            $index[$setting->id] = $setting;
        }

        if (array_keys($index) !== self::IDS) {
            throw new \InvalidArgumentException('Configuration catalog settings must be the approved list in order.');
        }

        $this->index = $index;
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function setting(string $id): ConfigurationSetting
    {
        if (! isset($this->index[$id])) {
            throw new \LogicException('Configuration catalog is missing a setting.');
        }

        return $this->index[$id];
    }

    public static function categoryFor(string $id): ?ConfigurationCategory
    {
        return match ($id) {
            'app.cipher', 'app.maintenance_driver', 'app.key' => ConfigurationCategory::Application,
            'database.default', 'database.connection', 'database.driver', 'database.password', 'database.url' => ConfigurationCategory::Database,
            'cache.default', 'cache.store', 'cache.driver' => ConfigurationCategory::Cache,
            'queue.default', 'queue.connection', 'queue.driver', 'queue.name' => ConfigurationCategory::Queue,
            'session.driver', 'session.secure', 'session.http_only', 'session.same_site', 'session.encrypt', 'session.cookie' => ConfigurationCategory::Session,
            'mail.default', 'mail.mailer', 'mail.transport', 'mail.scheme', 'mail.username', 'mail.password' => ConfigurationCategory::Mail,
            'filesystem.default', 'filesystem.disk', 'filesystem.driver', 'filesystem.visibility', 'filesystem.credentials' => ConfigurationCategory::Filesystem,
            'logging.default', 'logging.channel', 'logging.driver', 'logging.level', 'logging.handler', 'logging.webhook' => ConfigurationCategory::Logging,
            default => null,
        };
    }
}
