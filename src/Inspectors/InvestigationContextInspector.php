<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\ApplicationInfo;
use Beacon\Investigation\ContextBuilder;
use Beacon\Investigation\EvidenceCollector;
use Beacon\Investigation\InvestigationQuery;
use Beacon\Investigation\InvestigationScope;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Builds a normalized investigation context from existing Beacon evidence sources.
 *
 * @implements Inspector<\Beacon\Data\InvestigationContext>
 */
final class InvestigationContextInspector implements Inspector
{
    public const NAME = 'investigation_context';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly EvidenceCollector $collector,
        private readonly ContextBuilder $builder,
        private readonly ApplicationInspector $application,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::InvestigationContext->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $query = InvestigationQuery::from($parameters);
        $limits = $this->config->investigationLimits();
        $scope = InvestigationScope::from($query, $limits);
        $evidence = $this->collector->collect($scope);

        $applicationName = null;
        $environment = $evidence->runtimeFacts['environment'] ?? null;

        try {
            /** @var ApplicationInfo $application */
            $application = $this->application->inspect([]);
            $applicationName = $application->name;
        } catch (\Throwable) {
            // Application metadata is optional for investigation context.
        }

        if (! is_string($environment)) {
            $environment = $this->config->environment();
        }

        return $this->builder->build(
            $scope,
            $evidence,
            $applicationName,
            $environment,
        );
    }
}
