<?php

declare(strict_types=1);

namespace Beacon\Database;

/**
 * Whether the default database connection could be read.
 *
 * connected: a read-only probe completed.
 * unavailable: the probe was attempted and failed.
 * unknown: Beacon could not safely decide, and did not open a connection.
 * unsupported: the driver is recognised, and this release does not open it.
 */
enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
    case Unsupported = 'unsupported';
}
