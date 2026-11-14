<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Read-only runtime inspection of the current PHP and Laravel process.
 */
final readonly class RuntimeInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    public function __construct(
        public PhpRuntime $php,
        public LaravelRuntime $laravel,
        public RuntimeDrivers $drivers,
        public string $summary,
        public ?string $beaconVersion,
    ) {
        if (! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Runtime summary must be safe prose.');
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
