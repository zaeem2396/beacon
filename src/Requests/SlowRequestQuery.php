<?php

declare(strict_types=1);

namespace Beacon\Requests;

use Beacon\Exceptions\InvalidRequestQueryException;

/**
 * Filters for slow-request analysis over recorded observations.
 */
final class SlowRequestQuery
{
    public const PARAMETERS = [
        'since',
        'until',
        'method',
        'route',
        'status',
        'min_duration_ms',
        'limit',
        'page',
        'window_minutes',
        'max_endpoints',
    ];

    public function __construct(
        public readonly RequestQuery $request,
        public readonly int $windowMinutes,
        public readonly int $maxEndpoints,
    ) {
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function from(array $parameters, int $defaultWindow, int $defaultLimit, int $defaultEndpoints): self
    {
        foreach (array_keys($parameters) as $key) {
            if (! in_array($key, self::PARAMETERS, true)) {
                throw InvalidRequestQueryException::filter('Slow request inspection rejected an unknown parameter.');
            }
        }

        $window = $defaultWindow;

        if (array_key_exists('window_minutes', $parameters) && $parameters['window_minutes'] !== null && $parameters['window_minutes'] !== '') {
            $value = $parameters['window_minutes'];
            $window = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : -1);

            if ($window < 1 || $window > 10080) {
                throw InvalidRequestQueryException::filter('Slow request inspection rejected the window.');
            }
        }

        $maxEndpoints = $defaultEndpoints;

        if (array_key_exists('max_endpoints', $parameters) && $parameters['max_endpoints'] !== null && $parameters['max_endpoints'] !== '') {
            $value = $parameters['max_endpoints'];
            $maxEndpoints = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : -1);

            if ($maxEndpoints < 1 || $maxEndpoints > 100) {
                throw InvalidRequestQueryException::filter('Slow request inspection rejected the endpoint limit.');
            }
        }

        $limit = $parameters['limit'] ?? $defaultLimit;
        $nested = [
            'since' => $parameters['since'] ?? null,
            'until' => $parameters['until'] ?? null,
            'method' => $parameters['method'] ?? null,
            'route' => $parameters['route'] ?? null,
            'status' => $parameters['status'] ?? null,
            'min_duration_ms' => $parameters['min_duration_ms'] ?? null,
            'limit' => $limit,
            'page' => $parameters['page'] ?? 1,
        ];

        if (($nested['since'] === null || $nested['since'] === '') && ($nested['until'] === null || $nested['until'] === '')) {
            $nested['since'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->sub(new \DateInterval('PT' . $window . 'M'))
                ->format('Y-m-d\\TH:i:s.vP');
        }

        /** @var array<string, scalar|null> $nested */
        return new self(RequestQuery::from($nested), $window, $maxEndpoints);
    }
}
