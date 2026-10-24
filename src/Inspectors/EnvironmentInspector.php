<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\ConfigurationState;
use Beacon\Data\EnvironmentFacts;
use Beacon\Data\EnvironmentInspection;
use Beacon\Data\EnvironmentSetting;
use Beacon\Inspectors\Environment\EnvironmentAnalyzer;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Beacon\Security\UrlSanitizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as LaravelApplication;

/**
 * Collects allowlisted environment facts and evaluates them with {@see EnvironmentAnalyzer}.
 *
 * The inspector reads Laravel's effective configuration. It does not open the
 * .env file, getenv(), or $_SERVER, and it does not return the application
 * identity report produced by {@see ApplicationInspector}.
 *
 * @implements Inspector<EnvironmentInspection>
 */
final class EnvironmentInspector implements Inspector
{
    public const NAME = 'environment';

    /**
     * Language tag such as "en", "pt_BR", "zh-Hant-TW" or "sr_Latn_RS".
     */
    private const LOCALE_PATTERN = '/^[A-Za-z]{2,8}([_-][A-Za-z0-9]{1,8}){0,3}$/';

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly UrlSanitizer $urls,
        private readonly EnvironmentAnalyzer $analyzer,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Environment->capability();
    }

    /**
     * The environment inspector takes no parameters; any given are ignored.
     */
    public function inspect(array $parameters = []): Data
    {
        $debug = $this->booleanSetting('app.debug');
        $timezone = $this->timezoneSetting();
        $locale = $this->localeSetting('app.locale');

        $facts = new EnvironmentFacts(
            environment: $this->app->environment(),
            debug: $debug->state === ConfigurationState::Present && is_bool($debug->value) ? $debug->value : null,
            timezone: is_string($timezone->value) ? $timezone->value : null,
            locale: is_string($locale->value) ? $locale->value : null,
            configurationCached: $this->configurationCached(),
            routesCached: $this->routesCached(),
            settings: [
                $debug,
                $timezone,
                $locale,
                $this->urlSetting(),
                $this->keySetting(),
                $this->booleanSetting('session.secure'),
                $this->booleanSetting('session.http_only'),
                $this->sameSiteSetting(),
            ],
        );

        $inspection = $this->analyzer->analyze($facts);

        if (! $inspection instanceof EnvironmentInspection) {
            throw new \RuntimeException('Environment analysis did not return an environment inspection.');
        }

        return $inspection;
    }

    private function booleanSetting(string $name): EnvironmentSetting
    {
        $value = $this->config->get($name);

        if ($value === null || $value === '') {
            return new EnvironmentSetting($name, ConfigurationState::Missing, null);
        }

        if (! is_bool($value)) {
            return new EnvironmentSetting($name, ConfigurationState::Invalid, null);
        }

        return new EnvironmentSetting($name, ConfigurationState::Present, $value);
    }

    private function timezoneSetting(): EnvironmentSetting
    {
        $timezone = $this->config->get('app.timezone');

        if ($timezone === null || (is_string($timezone) && trim($timezone) === '')) {
            return new EnvironmentSetting('app.timezone', ConfigurationState::Missing, null);
        }

        if (! is_string($timezone)) {
            return new EnvironmentSetting('app.timezone', ConfigurationState::Invalid, null);
        }

        try {
            $name = (new \DateTimeZone(trim($timezone)))->getName();
        } catch (\Throwable) {
            return new EnvironmentSetting('app.timezone', ConfigurationState::Invalid, null);
        }

        return new EnvironmentSetting('app.timezone', ConfigurationState::Present, $name);
    }

    private function localeSetting(string $key): EnvironmentSetting
    {
        $locale = $this->config->get($key);

        if ($locale === null || (is_string($locale) && trim($locale) === '')) {
            return new EnvironmentSetting($key, ConfigurationState::Missing, null);
        }

        if (! is_string($locale) || preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
            return new EnvironmentSetting($key, ConfigurationState::Invalid, null);
        }

        return new EnvironmentSetting($key, ConfigurationState::Present, $locale);
    }

    /**
     * The value is the scheme only. Host, credentials, query and fragment are dropped.
     */
    private function urlSetting(): EnvironmentSetting
    {
        $url = $this->config->get('app.url');

        if ($url === null || (is_string($url) && trim($url) === '')) {
            return new EnvironmentSetting('app.url', ConfigurationState::Missing, null);
        }

        $sanitized = $this->urls->sanitize($url);

        if ($sanitized === null) {
            return new EnvironmentSetting('app.url', ConfigurationState::Invalid, null);
        }

        return new EnvironmentSetting('app.url', ConfigurationState::Present, str_starts_with($sanitized, 'https://') ? 'https' : 'http');
    }

    /**
     * Presence only. The key, previous keys, and any other value are never copied.
     */
    private function keySetting(): EnvironmentSetting
    {
        $value = $this->config->get('app.key');
        $present = (is_string($value) && trim($value) !== '') || (is_array($value) && $value !== []);

        return new EnvironmentSetting(
            'app.key',
            $present ? ConfigurationState::Sensitive : ConfigurationState::Missing,
            null,
        );
    }

    private function sameSiteSetting(): EnvironmentSetting
    {
        $value = $this->config->get('session.same_site');

        if ($value === null || $value === '') {
            return new EnvironmentSetting('session.same_site', ConfigurationState::Missing, null);
        }

        if (! is_string($value) || ! in_array(strtolower(trim($value)), ['lax', 'strict', 'none'], true)) {
            return new EnvironmentSetting('session.same_site', ConfigurationState::Invalid, null);
        }

        return new EnvironmentSetting('session.same_site', ConfigurationState::Present, strtolower(trim($value)));
    }

    /**
     * Null when this process is not the Laravel application, or the probe fails.
     * The cached file path is never returned.
     */
    private function configurationCached(): ?bool
    {
        if (! $this->app instanceof LaravelApplication) {
            return null;
        }

        try {
            return $this->app->configurationIsCached();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Null when this process is not the Laravel application, or the probe fails.
     * The cached file path is never returned.
     */
    private function routesCached(): ?bool
    {
        if (! $this->app instanceof LaravelApplication) {
            return null;
        }

        try {
            return $this->app->routesAreCached();
        } catch (\Throwable) {
            return null;
        }
    }
}
