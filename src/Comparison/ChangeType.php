<?php

declare(strict_types=1);

namespace Beacon\Comparison;

enum ChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Modified = 'modified';
    case Renamed = 'renamed';
    case Unknown = 'unknown';
}
