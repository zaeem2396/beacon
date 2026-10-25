<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

/**
 * The default cache store must name a registered store and a recognised
 * driver. This check does not contact the cache.
 */
final class CacheStore extends ReferenceCheck
{
    public const ID = 'cache_store';

    protected function checkId(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return 'Default cache store';
    }

    protected function nameId(): string
    {
        return 'cache.default';
    }

    protected function definitionId(): string
    {
        return 'cache.store';
    }

    protected function driverId(): string
    {
        return 'cache.driver';
    }

    protected function subject(): string
    {
        return 'default cache store';
    }
}
