<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Environment;

use Beacon\Data\EnvironmentFacts;
use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;

/**
 * One deterministic environment check.
 *
 * Implementations decide from {@see EnvironmentFacts} only. They do not read
 * configuration, the environment, or the filesystem.
 */
abstract class EnvironmentCheck
{
    protected const OUTSIDE_PRODUCTION = 'This check only reports a misconfiguration when the environment is exactly "production".';

    abstract public function evaluate(EnvironmentFacts $facts): Finding;

    /**
     * @param list<FindingEvidence> $evidence
     */
    final protected function finding(
        string $id,
        FindingStatus $status,
        Severity $severity,
        string $title,
        string $description,
        array $evidence,
        ?string $remediation,
    ): Finding {
        return new Finding($id, $status, $severity, $title, $description, $evidence, $remediation);
    }

    final protected function environmentEvidence(EnvironmentFacts $facts): FindingEvidence
    {
        return new FindingEvidence(
            'environment',
            $facts->isProduction() ? Observation::Production : Observation::NotProduction,
        );
    }

    /**
     * @param list<FindingEvidence> $evidence
     */
    final protected function passOutsideProduction(EnvironmentFacts $facts, string $id, string $title, array $evidence): ?Finding
    {
        if ($facts->isProduction()) {
            return null;
        }

        return $this->finding($id, FindingStatus::Pass, Severity::None, $title, self::OUTSIDE_PRODUCTION, $evidence, null);
    }
}
