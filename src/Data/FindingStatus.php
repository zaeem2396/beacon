<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * The outcome of one deterministic check.
 *
 * Fail is a confirmed misconfiguration. Risk is a potential problem supported
 * by evidence that is not, by itself, a confirmed misconfiguration. Unknown
 * means the check could not be evaluated. None of these values means "vulnerable".
 */
enum FindingStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Risk = 'risk';
    case Unknown = 'unknown';
}
