<?php

declare(strict_types=1);

namespace Beacon\Core;

use Composer\InstalledVersions;

/**
 * The installed Beacon version, read from Composer's metadata so it never
 * goes stale.
 */
final class Version
{
    public const PACKAGE = 'zaeem2396/beacon';

    public const UNKNOWN = 'unknown';

    public static function current(): string
    {
        try {
            if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled(self::PACKAGE)) {
                return self::UNKNOWN;
            }

            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? self::UNKNOWN;
        } catch (\Throwable) {
            return self::UNKNOWN;
        }
    }
}
