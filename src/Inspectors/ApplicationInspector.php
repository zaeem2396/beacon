<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\Version;
use Beacon\Data\ApplicationInfo;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Beacon\Security\UrlSanitizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Collects safe metadata about the Laravel application and its PHP runtime.
 *
 * Only an explicit list of values is read. Each optional value is validated on
 * its own and becomes null when it is missing, invalid or cannot be determined,
 * so one broken setting neither fails the inspection nor passes through as-is.
 *
 * @implements Inspector<ApplicationInfo>
 */
final class ApplicationInspector implements Inspector
{
    public const NAME = 'application';

    public const MAX_NAME_LENGTH = 255;

    /**
     * Language tag such as "en", "pt_BR", "zh-Hant-TW" or "sr_Latn_RS".
     */
    private const LOCALE_PATTERN = '/^[A-Za-z]{2,8}([_-][A-Za-z0-9]{1,8}){0,3}$/';

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly UrlSanitizer $urls,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Application->capability();
    }

    /**
     * The application inspector takes no parameters; any given are ignored.
     */
    public function inspect(array $parameters = []): Data
    {
        $beaconVersion = Version::current();

        return new ApplicationInfo(
            name: $this->name(),
            environment: $this->app->environment(),
            debug: $this->debug(),
            url: $this->urls->sanitize($this->config->get('app.url')),
            timezone: $this->timezone(),
            locale: $this->locale('app.locale'),
            fallbackLocale: $this->locale('app.fallback_locale'),
            maintenanceMode: $this->maintenanceMode(),
            laravelVersion: $this->app->version(),
            phpVersion: PHP_VERSION,
            beaconVersion: $beaconVersion === Version::UNKNOWN ? null : $beaconVersion,
        );
    }

    private function name(): ?string
    {
        $name = $this->config->get('app.name');

        if (! is_string($name) || ($name = trim($name)) === '') {
            return null;
        }

        // Also rejects invalid UTF-8, which could not be serialized.
        if (preg_match('/\p{Cc}/u', $name) !== 0 || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return null;
        }

        return $name;
    }

    /**
     * Only a real boolean is reported: Laravel treats any truthy value as
     * enabled, so reporting a string such as "false" as false would be wrong.
     */
    private function debug(): ?bool
    {
        $debug = $this->config->get('app.debug');

        return is_bool($debug) ? $debug : null;
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

    private function locale(string $key): ?string
    {
        $locale = $this->config->get($key);

        return is_string($locale) && preg_match(self::LOCALE_PATTERN, $locale) === 1 ? $locale : null;
    }

    /**
     * Unknown when the maintenance-mode driver cannot be read, e.g. when the
     * cache store backing it is unavailable.
     */
    private function maintenanceMode(): ?bool
    {
        try {
            return $this->app->isDownForMaintenance();
        } catch (\Throwable) {
            return null;
        }
    }
}
