<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\HealthStatus;

/**
 * The normalized result of one health check.
 *
 * Evidence is a classification, never a copied configuration value, exception
 * message, or connection string. A healthy result has no remediation. Every
 * other status explains what a person can do next.
 */
final readonly class HealthCheckResult extends DataObject
{
    /**
     * @param list<FindingEvidence> $evidence
     */
    public function __construct(
        public string $name,
        public string $description,
        public HealthStatus $status,
        public string $summary,
        public ?int $durationMs,
        public ?string $remediation,
        public array $evidence,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Health check name must be a lowercase identifier.');
        }

        if (! $this->prose($description) || ! $this->prose($summary)) {
            throw new \InvalidArgumentException('Health check text must be short prose.');
        }

        if ($durationMs !== null && ($durationMs < 0 || $durationMs > 120000)) {
            throw new \InvalidArgumentException('Health check duration is outside the allowed range.');
        }

        $guidance = $remediation !== null && $this->prose($remediation);

        if ($status === HealthStatus::Healthy && $remediation !== null) {
            throw new \InvalidArgumentException('A healthy check does not include remediation.');
        }

        if ($status !== HealthStatus::Healthy && ! $guidance) {
            throw new \InvalidArgumentException('A check that is not healthy must include remediation.');
        }

        if (count($evidence) > 8) {
            throw new \InvalidArgumentException('A health check has too much evidence.');
        }

        foreach ($evidence as $item) {
            if (! $item instanceof FindingEvidence) {
                throw new \InvalidArgumentException('Health check evidence must contain only evidence objects.');
            }
        }
    }

    public function withDuration(int $durationMs): self
    {
        return new self($this->name, $this->description, $this->status, $this->summary, $durationMs, $this->remediation, $this->evidence);
    }

    private function prose(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 .,_-]{0,239}$/', $value) === 1;
    }
}
