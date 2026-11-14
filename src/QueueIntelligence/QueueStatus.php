<?php

declare(strict_types=1);

namespace Beacon\QueueIntelligence;

enum QueueStatus: string
{
    case Ready = 'ready';
    case Unavailable = 'unavailable';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
}
