<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\RequestSummaryInspection;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\RequestStore;
use Beacon\Requests\RequestSummaryBuilder;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Request summary metrics from the same inspected request window.
 *
 * @implements Inspector<RequestSummaryInspection>
 */
final class RequestSummaryInspector implements Inspector
{
    public const NAME = 'request_summary';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly RequestStore $store,
        private readonly RequestSummaryBuilder $summaries = new RequestSummaryBuilder(),
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::RequestSummary->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $query = RequestQuery::from($parameters);

        if (! $this->config->requestCollectionEnabled()) {
            return new RequestSummaryInspection(
                $this->summaries->build([], 0, 0, false, 'disabled', 'Request collection is disabled.'),
            );
        }

        if (! $this->store->available()) {
            return new RequestSummaryInspection(
                $this->summaries->build([], 0, 0, false, 'unavailable', 'Request storage is unavailable.'),
            );
        }

        $search = $this->store->search($query);
        $offset = ($query->page - 1) * $query->limit;
        $pageCount = count(array_slice($search['records'], $offset, $query->limit));

        return new RequestSummaryInspection(
            $this->summaries->build(
                $search['records'],
                $pageCount,
                $search['total'],
                $search['truncated'],
                'enabled',
                $search['total'] === 0
                    ? 'No request records matched the inspected window.'
                    : 'Request metrics cover only the inspected storage window.',
            ),
        );
    }
}
