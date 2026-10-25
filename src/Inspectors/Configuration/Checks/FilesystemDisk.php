<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

/**
 * The default filesystem disk must name a registered disk and a recognised
 * driver. This check does not read or write files.
 */
final class FilesystemDisk extends ReferenceCheck
{
    public const ID = 'filesystem_disk';

    protected function checkId(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return 'Default filesystem disk';
    }

    protected function nameId(): string
    {
        return 'filesystem.default';
    }

    protected function definitionId(): string
    {
        return 'filesystem.disk';
    }

    protected function driverId(): string
    {
        return 'filesystem.driver';
    }

    protected function subject(): string
    {
        return 'default filesystem disk';
    }
}
