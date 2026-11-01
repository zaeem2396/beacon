<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * Which migration method contained the operation.
 *
 * down is not assumed to reverse up.
 */
enum MigrationPhase: string
{
    case Up = 'up';
    case Down = 'down';
}
