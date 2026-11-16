<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\SlowEndpointRecord;
use Beacon\Data\SlowRequestSummaryInspection;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\RequestStore;
use Beacon\Requests\SlowRequestAggregator;
use Beacon\Requests\SlowRequestDetector;
use Beacon\Requests\SlowRequestQuery;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Endpoint latency summaries for the inspected request window.
 *
 * @implements Inspector<SlowRequestSummaryInspection>
 */
final class SlowRequestSummaryInspector implements Inspector
{
    public const NAME = 'slow_request_summary';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly RequestStore $store,
        private readonly SlowRequestAggregator $aggregator = new SlowRequestAggregator(),
        private readonly SlowRequestDetector $detector = new SlowRequestDetector(),
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::SlowRequestSummary->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $thresholds = $this->config->slowRequestThresholds();
        $minimumSamples = $this->config->slowRequestMinimumSamples();
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
                $minimumSamples,
                'disabled',
                'Request collection is disabled.',
                ['Set BEACON_REQUESTS_COLLECTION to true after publishing the Beacon requests migration.'],
            );
        }

        if (! $this->store->available()) {
            return $this->empty(
                $query,
                $thresholds->defaultMs,
                $minimumSamples,
                'unavailable',
                'Request storage is unavailable.',
                ['Publish and run the Beacon requests migration before summarizing slow requests.'],
            );
        }

        $analysis = RequestQuery::analysis(
            $query->request->since,
            $query->request->until,
            $query->request->method,
            $query->request->route,
            $query->request->status,
            $query->request->minDurationMs,
            RequestQuery::MAX_ANALYSIS,
        );
        $search = $this->store->search($analysis);
        $slow = $this->detector->detect($search['records'], $thresholds);
        $endpoints = $this->aggregator->summarize(
            $search['records'],
            $thresholds,
            $minimumSamples,
            $query->maxEndpoints,
        );
        $records = [];

        foreach ($endpoints as $endpoint) {
            $records[] = new SlowEndpointRecord(
                method: $endpoint->method,
                endpoint: $endpoint->endpoint,
                thresholdMs: $endpoint->thresholdMs,
                samples: $endpoint->samples,
                slowCount: $endpoint->slowCount,
                slowPercent: $endpoint->slowPercent,
                averageMs: $endpoint->averageMs,
                medianMs: $endpoint->medianMs,
                p95Ms: $endpoint->p95Ms,
                p99Ms: $endpoint->p99Ms,
                maximumMs: $endpoint->maximumMs,
                byStatus: $endpoint->byStatus,
                exceptionCount: $endpoint->exceptionCount,
                sufficientSamples: $endpoint->sufficientSamples,
            );
        }

        $warnings = [];

        if ($search['truncated']) {
            $warnings[] = 'Slow request summary stopped at the analysis record limit.';
        }

        return new SlowRequestSummaryInspection(
            endpoints: $records,
            returned: count($records),
            inspected: $search['total'],
            slowCount: count($slow),
            thresholdMs: $thresholds->defaultMs,
            windowMinutes: $query->windowMinutes,
            minimumSamples: $minimumSamples,
            truncated: $search['truncated'],
            status: count($records) === 0 ? 'empty' : 'ready',
            summary: count($records) === 0
                ? 'No endpoint summaries matched the inspected window.'
                : 'Endpoint latency was summarized from recorded observations.',
            warnings: $warnings,
        );
    }

    /**
     * @param list<string> $warnings
     */
    private function empty(
        SlowRequestQuery $query,
        int $thresholdMs,
        int $minimumSamples,
        string $status,
        string $summary,
        array $warnings,
    ): SlowRequestSummaryInspection {
        return new SlowRequestSummaryInspection(
            endpoints: [],
            returned: 0,
            inspected: 0,
            slowCount: 0,
            thresholdMs: $thresholdMs,
            windowMinutes: $query->windowMinutes,
            minimumSamples: $minimumSamples,
            truncated: false,
            status: $status,
            summary: $summary,
            warnings: $warnings,
        );
    }
}
