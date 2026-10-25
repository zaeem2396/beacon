<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * An approved configuration category.
 *
 * Categories are a closed set. They are not Laravel configuration paths, and
 * a caller cannot add one by naming a namespace.
 */
enum ConfigurationCategory: string
{
    case Application = 'application';
    case Database = 'database';
    case Cache = 'cache';
    case Queue = 'queue';
    case Session = 'session';
    case Mail = 'mail';
    case Filesystem = 'filesystem';
    case Logging = 'logging';
}
