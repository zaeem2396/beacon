<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * Default and endpoint-specific slow-request thresholds in milliseconds.
 *
 * A request is slow when duration_ms is greater than the applicable threshold.
 * Equal durations are not slow.
 */
final class SlowThresholds
{
    public const DEFAULT_MS = 1000;

    public const MIN_MS = 1;

    public const MAX_MS = 3_600_000;

    /**
     * @param array<string, int> $endpoints
     */
    public function __construct(
        public readonly int $defaultMs,
        public readonly array $endpoints,
    ) {
    }

    public function for(RequestObservation $observation): int
    {
        foreach ($this->keys($observation) as $key) {
            if (isset($this->endpoints[$key])) {
                return $this->endpoints[$key];
            }
        }

        return $this->defaultMs;
    }

    public function floor(): int
    {
        $values = $this->endpoints;
        $values[] = $this->defaultMs;

        return max(self::MIN_MS, min($values));
    }

    /**
     * @return list<string>
     */
    private function keys(RequestObservation $observation): array
    {
        $keys = [];

        if ($observation->routeName !== null) {
            $keys[] = $observation->routeName;
        }

        $keys[] = $observation->routeUri;
        $keys[] = $observation->method . ' ' . $observation->routeUri;

        if ($observation->routeName !== null) {
            $keys[] = $observation->method . ' ' . $observation->routeName;
        }

        return $keys;
    }
}
