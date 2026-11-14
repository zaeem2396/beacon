<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Safe Laravel process facts for the current request or Artisan process.
 */
final readonly class LaravelRuntime extends DataObject
{
    public function __construct(
        public string $version,
        public string $environment,
        public ?bool $debug,
        public ?string $timezone,
        public ?string $locale,
        public bool $booted,
        public ?bool $maintenanceMode,
    ) {
    }
}
