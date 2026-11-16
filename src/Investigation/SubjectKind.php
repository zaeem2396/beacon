<?php

declare(strict_types=1);

namespace Beacon\Investigation;

/**
 * Kind of primary investigation subject.
 */
enum SubjectKind: string
{
    case Request = 'request';
    case Correlation = 'correlation';
    case Exception = 'exception';
    case Fingerprint = 'fingerprint';
    case Endpoint = 'endpoint';
    case TimeWindow = 'time_window';
    case Insufficient = 'insufficient';
}
