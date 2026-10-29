<?php

declare(strict_types=1);

namespace Beacon\Database;

/**
 * The raw result of one read-only database probe.
 *
 * The version is unsanitized server text. Callers keep only a short version
 * and drop this object. It is not serialized to clients.
 */
final readonly class ProbeMeasurement
{
    public function __construct(
        public string $version,
        public int $latencyMs,
    ) {
    }
}
