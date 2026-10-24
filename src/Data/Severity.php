<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * How serious a confirmed or potential finding is.
 *
 * Passing and unknown findings use {@see None}: a check that did not
 * establish a problem does not borrow the severity it would have had.
 */
enum Severity: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
