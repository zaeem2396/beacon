<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * How sure the analyzer is that the operation was recognized.
 *
 * High means a literal call was found. It does not mean the database will
 * fail, lock, or lose data.
 */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
