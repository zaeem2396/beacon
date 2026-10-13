<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Safe metadata describing the Laravel application and the PHP runtime that
 * serves it.
 *
 * Optional values are null when they are missing, invalid or could not be
 * determined; they are never replaced by guesses or defaults.
 */
final readonly class ApplicationInfo extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    public function __construct(
        public ?string $name,
        public string $environment,
        public ?bool $debug,
        public ?string $url,
        public ?string $timezone,
        public ?string $locale,
        public ?string $fallbackLocale,
        public ?bool $maintenanceMode,
        public string $laravelVersion,
        public string $phpVersion,
        public ?string $beaconVersion,
    ) {
        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
