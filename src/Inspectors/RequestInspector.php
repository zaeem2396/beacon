<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\RequestInspection;
use Beacon\Data\RequestRecord;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\RequestStore;
use Beacon\Requests\RequestSummaryBuilder;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Recent HTTP requests from the opt-in Beacon request store.
 *
 * @implements Inspector<RequestInspection>
 */
final class RequestInspector implements Inspector
{
    public const NAME = 'requests';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly RequestStore $store,
        private readonly RequestSummaryBuilder $summaries = new RequestSummaryBuilder(),
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Requests->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $query = RequestQuery::from($parameters);

        if (! $this->config->requestCollectionEnabled()) {
            return $this->empty(
                'disabled',
                'Request collection is disabled.',
                ['Set BEACON_REQUESTS_COLLECTION to true after publishing the Beacon requests migration.'],
            );
        }

        if (! $this->store->available()) {
            return $this->empty(
                'unavailable',
                'Request storage is unavailable.',
                ['Publish and run the Beacon requests migration before inspecting request history.'],
            );
        }

        $search = $this->store->search($query);
        $offset = ($query->page - 1) * $query->limit;
        $page = array_slice($search['records'], $offset, $query->limit);
        /** @var list<RequestRecord> $records */
        $records = [];

        foreach ($page as $observation) {
            $records[] = $this->summaries->toRecord($observation);
        }

        $summary = $this->summaries->build(
            $search['records'],
            count($records),
            $search['total'],
            $search['truncated'] || count($search['records']) > count($records),
            'enabled',
            $search['total'] === 0
                ? 'No request records matched the inspected window.'
                : 'Request records were read from Beacon request storage.',
        );

        return new RequestInspection(
            requests: $records,
            summary: $summary,
            summaryText: $summary->limitation,
            warnings: $search['truncated'] ? ['Request output stopped at the configured limit.'] : [],
        );
    }

    /**
     * @param list<string> $warnings
     */
    private function empty(string $collection, string $limitation, array $warnings): RequestInspection
    {
        $summary = $this->summaries->build([], 0, 0, false, $collection, $limitation);

        return new RequestInspection(
            requests: [],
            summary: $summary,
            summaryText: $limitation,
            warnings: $warnings,
        );
    }
}
