<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One deterministic check result.
 *
 * Findings are not specific to environment inspection. Later health and
 * deployment checks can return the same shape without depending on MCP.
 */
final readonly class Finding extends DataObject
{
    /**
     * @param list<FindingEvidence> $evidence
     */
    public function __construct(
        public string $id,
        public FindingStatus $status,
        public Severity $severity,
        public string $title,
        public string $description,
        public array $evidence,
        public ?string $remediation,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $id) !== 1) {
            throw new \InvalidArgumentException('Finding id must be a lowercase identifier.');
        }

        if (trim($title) === '' || trim($description) === '') {
            throw new \InvalidArgumentException('A finding needs a title and a description.');
        }

        foreach ($evidence as $item) {
            if (! $item instanceof FindingEvidence) {
                throw new \InvalidArgumentException('Finding evidence must contain only evidence objects.');
            }
        }

        $evaluated = $status === FindingStatus::Fail || $status === FindingStatus::Risk;

        if ($evaluated && $severity === Severity::None) {
            throw new \InvalidArgumentException('A failed or risk finding must name its severity.');
        }

        if (! $evaluated && $severity !== Severity::None) {
            throw new \InvalidArgumentException('A passing or unknown finding has no severity.');
        }

        $guidance = $remediation !== null && trim($remediation) !== '';

        if ($status === FindingStatus::Pass && $remediation !== null) {
            throw new \InvalidArgumentException('A passing finding does not include remediation.');
        }

        if ($status !== FindingStatus::Pass && ! $guidance) {
            throw new \InvalidArgumentException('A finding that is not a pass must include remediation.');
        }
    }
}
