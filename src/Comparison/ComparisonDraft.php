<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Data\SchemaDifference;

/**
 * The unfiltered comparison, before pagination.
 */
final readonly class ComparisonDraft
{
    /**
     * @param list<SchemaDifference> $differences
     * @param list<string> $limitations
     */
    public function __construct(
        public array $differences,
        public bool $complete,
        public array $limitations,
    ) {
    }
}
