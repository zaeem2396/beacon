<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

/**
 * The default queue connection must name a registered connection and a
 * recognised driver. This check does not enqueue a job.
 */
final class QueueConnection extends ReferenceCheck
{
    public const ID = 'queue_connection';

    protected function checkId(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return 'Default queue connection';
    }

    protected function nameId(): string
    {
        return 'queue.default';
    }

    protected function definitionId(): string
    {
        return 'queue.connection';
    }

    protected function driverId(): string
    {
        return 'queue.driver';
    }

    protected function subject(): string
    {
        return 'default queue connection';
    }
}
