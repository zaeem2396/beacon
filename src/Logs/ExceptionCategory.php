<?php

declare(strict_types=1);

namespace Beacon\Logs;

enum ExceptionCategory: string
{
    case Database = 'database';
    case Connection = 'connection';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Validation = 'validation';
    case Http = 'http';
    case Queue = 'queue';
    case Filesystem = 'filesystem';
    case External = 'external';
    case Php = 'php';
    case Memory = 'memory';
    case Timeout = 'timeout';
    case Unknown = 'unknown';
}
