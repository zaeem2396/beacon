<?php

declare(strict_types=1);

namespace Beacon\Comparison;

/**
 * How sure the comparator is that the structural change was identified.
 *
 * Confidence is independent of severity. A heuristic rename can be medium
 * confidence even when a dropped column would otherwise be high severity.
 */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
