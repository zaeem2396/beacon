<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\ExceptionInspection;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Logs\ExceptionCollector;
use Beacon\Logs\ExceptionQuery;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Recent exceptions from configured file-based logging channels.
 *
 * @implements Inspector<ExceptionInspection>
 */
final class ExceptionInspector implements Inspector
{
    public const NAME = 'exceptions';

    public function __construct(
        private readonly ExceptionCollector $collector,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Exceptions->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $query = ExceptionQuery::from($parameters);

        try {
            $collected = $this->collector->collect($query);
        } catch (InvalidLogQueryException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return new ExceptionInspection(
                exceptions: [],
                returned: 0,
                inspected: 0,
                truncated: false,
                summary: 'Exception sources were unavailable.',
                warnings: ['Supported file log channels could not be read.'],
            );
        }

        $offset = ($query->page - 1) * $query->limit;
        $page = array_slice($collected['records'], $offset, $query->limit);
        $warnings = [];

        if ($collected['truncated']) {
            $warnings[] = 'Log reading stopped at the configured byte or record limit.';
        }

        if ($collected['files'] === 0) {
            $warnings[] = 'No supported file log channels were readable.';
        }

        return new ExceptionInspection(
            exceptions: $page,
            returned: count($page),
            inspected: $collected['inspected'],
            truncated: $collected['truncated'] || count($collected['records']) > count($page),
            summary: count($page) === 0
                ? 'No matching exceptions were found in the inspected window.'
                : 'Exceptions were read from supported file log channels.',
            warnings: $warnings,
        );
    }
}
