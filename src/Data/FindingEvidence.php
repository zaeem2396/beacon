<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One piece of evidence attached to a finding.
 *
 * The name is the allowlisted setting or fact the check looked at. The
 * observation is a classification from {@see Observation}, not the raw value.
 */
final readonly class FindingEvidence extends DataObject
{
    public function __construct(
        public string $name,
        public Observation $observation,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Finding evidence name must be a lowercase identifier.');
        }
    }
}
