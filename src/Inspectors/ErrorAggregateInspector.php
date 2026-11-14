<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\ErrorAggregateInspection;
use Beacon\Data\ErrorSummary;
use Beacon\Logs\AggregateQuery;
use Beacon\Logs\ExceptionAggregator;
use Beacon\Logs\ExceptionCollector;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Groups recent exceptions for the inspected log window.
 *
 * @implements Inspector<ErrorAggregateInspection>
 */
final class ErrorAggregateInspector implements Inspector
{
    public const NAME = 'error_aggregate';

    public function __construct(
        private readonly ExceptionCollector $collector,
        private readonly ExceptionAggregator $aggregator = new ExceptionAggregator(),
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::ErrorAggregate->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $query = AggregateQuery::from($parameters);

        try {
            $collected = $this->collector->collect($query->exceptionQuery());
        } catch (\Throwable) {
            return new ErrorAggregateInspection(
                groups: [],
                summary: new ErrorSummary(
                    inspectedRecords: 0,
                    matchedRecords: 0,
                    uniqueGroups: 0,
                    returnedGroups: 0,
                    bySeverity: [],
                    byCategory: [],
                    byClass: [],
                    windowStart: null,
                    windowEnd: null,
                    truncated: false,
                    limitation: 'Exception sources were unavailable for aggregation.',
                ),
            );
        }

        $result = $this->aggregator->aggregate(
            $collected['records'],
            $query,
            $collected['truncated'],
            $collected['inspected'],
        );

        return new ErrorAggregateInspection(
            groups: $result['groups'],
            summary: $result['summary'],
        );
    }
}
