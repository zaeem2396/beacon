<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\SlowRequestInspection;
use Beacon\Data\SlowRequestRecord;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\RequestStore;
use Beacon\Requests\SlowRequest;
use Beacon\Requests\SlowRequestDetector;
use Beacon\Requests\SlowRequestQuery;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Lists recorded HTTP requests that exceeded configured duration thresholds.
 *
 * @implements Inspector<SlowRequestInspection>
 */
final class SlowRequestInspector implements Inspector
{
    public const NAME = 'slow_requests';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly RequestStore $store,
        private readonly SlowRequestDetector $detector = new SlowRequestDetector(),
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::SlowRequests->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $thresholds = $this->config->slowRequestThresholds();
        $query = SlowRequestQuery::from(
            $parameters,
            $this->config->slowRequestWindowMinutes(),
            $this->config->slowRequestLimit(),
            $this->config->slowRequestMaxEndpoints(),
        );

        if (! $this->config->requestCollectionEnabled()) {
            return $this->empty(
                $query,
                $thresholds->defaultMs,
                'disabled',
                'Request collection is disabled.',
                ['Set BEACON_REQUESTS_COLLECTION to true after publishing the Beacon requests migration.'],
            );
        }

        if (! $this->store->available()) {
            return $this->empty(
                $query,
                $thresholds->defaultMs,
                'unavailable',
                'Request storage is unavailable.',
                ['Publish and run the Beacon requests migration before inspecting slow requests.'],
            );
        }

        $analysis = RequestQuery::analysis(
            $query->request->since,
            $query->request->until,
            $query->request->method,
            $query->request->route,
            $query->request->status,
            max($query->request->minDurationMs ?? 0, $thresholds->floor()),
            RequestQuery::MAX_ANALYSIS,
        );
        $search = $this->store->search($analysis);
        $slow = $this->detector->detect($search['records'], $thresholds);
        $offset = ($query->request->page - 1) * $query->request->limit;
        $page = array_slice($slow, $offset, $query->request->limit);
        $records = [];

        foreach ($page as $item) {
            $records[] = $this->record($item);
        }

        $warnings = [];

        if ($search['truncated'] || count($slow) > count($page)) {
            $warnings[] = 'Slow request output stopped at the configured limit.';
        }

        return new SlowRequestInspection(
            requests: $records,
            returned: count($records),
            slowCount: count($slow),
            inspected: $search['total'],
            thresholdMs: $thresholds->defaultMs,
            windowMinutes: $query->windowMinutes,
            truncated: $search['truncated'] || count($slow) > count($records),
            status: count($records) === 0 ? 'empty' : 'ready',
            summary: count($records) === 0
                ? 'No slow requests matched the inspected window.'
                : 'Slow requests were classified from recorded observations.',
            warnings: $warnings,
        );
    }

    private function record(SlowRequest $slow): SlowRequestRecord
    {
        $observation = $slow->observation;

        return new SlowRequestRecord(
            requestId: $observation->requestId,
            correlationId: $observation->correlationId,
            method: $observation->method,
            routeName: $observation->routeName,
            routeUri: $observation->routeUri,
            action: $observation->action,
            status: $observation->status,
            durationMs: (int) $observation->durationMs,
            thresholdMs: $slow->thresholdMs,
            excessMs: $slow->excessMs,
            rank: $slow->rank,
            startedAt: $observation->startedAt,
            environment: $observation->environment,
            hadException: $observation->hadException,
            exceptionId: $observation->exceptionId,
            exceptionClass: $observation->exceptionClass,
            correlationKind: $observation->exceptionId !== null ? 'exception' : 'none',
        );
    }

    /**
     * @param list<string> $warnings
     */
    private function empty(
        SlowRequestQuery $query,
        int $thresholdMs,
        string $status,
        string $summary,
        array $warnings,
    ): SlowRequestInspection {
        return new SlowRequestInspection(
            requests: [],
            returned: 0,
            slowCount: 0,
            inspected: 0,
            thresholdMs: $thresholdMs,
            windowMinutes: $query->windowMinutes,
            truncated: false,
            status: $status,
            summary: $summary,
            warnings: $warnings,
        );
    }
}
