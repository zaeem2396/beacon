<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Comparison\CompareQuery;
use Beacon\Comparison\SchemaComparator;
use Beacon\Comparison\SnapshotDocument;
use Beacon\Comparison\SnapshotFactory;
use Beacon\Comparison\SnapshotLoader;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\ComparisonScope;
use Beacon\Data\ComparisonSummary;
use Beacon\Data\SchemaComparisonInspection;
use Beacon\Data\SchemaDifference;
use Beacon\Data\SchemaPage;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Health\Clock;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Compares two snapshots, or one snapshot and the live schema.
 *
 * Live capture uses schema inspection and requires the schema capability.
 * Saved snapshots are parsed as JSON and are not sent to the database.
 *
 * @implements Inspector<SchemaComparisonInspection>
 */
final class SchemaComparisonInspector implements Inspector
{
    public const NAME = 'schema_compare';

    public function __construct(
        private readonly SnapshotLoader $loader,
        private readonly SnapshotFactory $factory,
        private readonly SchemaComparator $comparator,
        private readonly BeaconConfig $config,
        private readonly Clock $clock,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::SchemaCompare->capability();
    }

    public function inspect(array $parameters = []): SchemaComparisonInspection
    {
        $query = CompareQuery::from($parameters);
        $live = $query->baselineLive || $query->candidateLive;

        if ($live && ! $this->schemaAllowed()) {
            throw new AuthorizationException('The schema capability is not allowed.');
        }

        $baseline = $query->baselineLive ? $this->capture($query->connection) : $this->loader->load((string) $query->baseline);
        $candidate = $query->candidateLive ? $this->capture($query->connection) : $this->loader->load((string) $query->candidate);
        $draft = $this->comparator->compare($baseline, $candidate, $query->detectRenames);
        $matched = $this->filter($draft->differences, $query);
        $offset = ($query->page - 1) * $query->limit;
        $page = array_slice($matched, $offset, $query->limit);
        $total = count($matched);
        $pages = $total === 0 ? 0 : (int) ceil($total / $query->limit);
        $counts = $this->counts($matched);

        return new SchemaComparisonInspection(
            new ComparisonSummary(
                $total,
                $counts['critical'],
                $counts['high'],
                $counts['medium'],
                $counts['low'],
                $counts['informational'],
                $draft->complete,
                $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\+00:00'),
                $this->summary($query, $draft->complete),
            ),
            new ComparisonScope(
                $query->filtered(),
                $query->category,
                $query->severity,
                $query->object,
                $query->detectRenames,
                $query->baselineLive,
                $query->candidateLive,
                $baseline->metadata->identifier,
                $candidate->metadata->identifier,
            ),
            new SchemaPage($query->page, $query->limit, $total, $pages, count($page)),
            $page,
            $draft->limitations,
            [],
        );
    }

    private function capture(?string $connection): SnapshotDocument
    {
        $inspection = $this->factory->capture($connection);

        return $inspection->snapshot;
    }

    /**
     * @param list<SchemaDifference> $differences
     *
     * @return list<SchemaDifference>
     */
    private function filter(array $differences, CompareQuery $query): array
    {
        $matched = [];

        foreach ($differences as $difference) {
            if ($query->category !== null && $difference->category !== $query->category) {
                continue;
            }

            if ($query->severity !== null && $difference->severity->rank() < $query->severity->rank()) {
                continue;
            }

            if ($query->object !== null && $difference->table !== $query->object && $difference->objectName !== $query->object && ! str_starts_with($difference->objectName, $query->object . '.')) {
                continue;
            }

            $matched[] = $difference;
        }

        return $matched;
    }

    /**
     * @param list<SchemaDifference> $differences
     *
     * @return array{critical: int, high: int, medium: int, low: int, informational: int}
     */
    private function counts(array $differences): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'informational' => 0];

        foreach ($differences as $difference) {
            $counts[$difference->severity->value]++;
        }

        return $counts;
    }

    private function summary(CompareQuery $query, bool $complete): string
    {
        if (! $complete) {
            return 'The comparison is incomplete. Missing metadata was not treated as a change.';
        }

        if ($query->filtered()) {
            return 'Counts follow the requested filters. Rows were not read.';
        }

        return 'The snapshots were compared without reading table rows.';
    }

    private function schemaAllowed(): bool
    {
        $allowed = $this->config->allowedCapabilities();

        return in_array('*', $allowed, true) || in_array(InspectionCapability::Schema->value, $allowed, true);
    }
}
