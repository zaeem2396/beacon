<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Comparison\ChangeType;
use Beacon\Comparison\ComparisonText;
use Beacon\Comparison\Confidence;
use Beacon\Comparison\DifferenceCategory;
use Beacon\Comparison\Severity;
use Beacon\Health\SafeName;

/**
 * One structural difference between two snapshots.
 *
 * before and after are normalized metadata values. They are not table rows.
 */
final readonly class SchemaDifference extends DataObject
{
    public function __construct(
        public string $id,
        public DifferenceCategory $category,
        public DifferenceCategory $objectType,
        public string $objectName,
        public ?string $table,
        public ChangeType $change,
        public ?string $field,
        public ?string $before,
        public ?string $after,
        public Severity $severity,
        public Confidence $confidence,
        public string $explanation,
        public bool $complete,
        public bool $heuristic,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]{0,180}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Schema difference id must be a stable identifier.');
        }

        if (! $this->object($objectName)) {
            throw new \InvalidArgumentException('Schema difference object name must be a plain identifier.');
        }

        if ($table !== null && ! SafeName::isIdentifier($table)) {
            throw new \InvalidArgumentException('Schema difference table must be a plain identifier.');
        }

        if ($field !== null && preg_match('/^[a-z][a-z0-9_]{0,40}$/', $field) !== 1) {
            throw new \InvalidArgumentException('Schema difference field must be a plain name.');
        }

        if (($before !== null && ! ComparisonText::isValue($before)) || ($after !== null && ! ComparisonText::isValue($after))) {
            throw new \InvalidArgumentException('Schema difference values must be plain metadata.');
        }

        ComparisonText::prose($explanation);
    }

    private function object(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}(?:\.[A-Za-z][A-Za-z0-9_-]{0,63})?$/', $name) === 1;
    }
}
