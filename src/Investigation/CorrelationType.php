<?php

declare(strict_types=1);

namespace Beacon\Investigation;

/**
 * How evidence relates to the investigation primary subject.
 */
enum CorrelationType: string
{
    case Direct = 'direct';
    case Inferred = 'inferred';
    case Uncorrelated = 'uncorrelated';
}
