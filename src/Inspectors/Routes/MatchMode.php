<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

/**
 * How a string filter is compared. Neither mode evaluates a regular expression.
 */
enum MatchMode: string
{
    case Contains = 'contains';
    case Exact = 'exact';
}
