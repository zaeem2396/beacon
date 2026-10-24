<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationState;
use Beacon\Data\Finding;

/**
 * The default logging channel must name a registered channel and a recognised
 * driver. An explicit unrecognised level fails. A missing level does not,
 * because Laravel supplies its own default. This check does not write a log.
 */
final class LoggingChannel extends ReferenceCheck
{
    public const ID = 'logging_channel';

    protected function checkId(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return 'Default logging channel';
    }

    protected function nameId(): string
    {
        return 'logging.default';
    }

    protected function definitionId(): string
    {
        return 'logging.channel';
    }

    protected function driverId(): string
    {
        return 'logging.driver';
    }

    protected function subject(): string
    {
        return 'default logging channel';
    }

    /**
     * @return list<\Beacon\Data\FindingEvidence>
     */
    protected function additionalEvidence(ConfigurationCatalog $catalog): array
    {
        return [$this->evidence('logging.level', $this->observation($catalog->setting('logging.level')))];
    }

    /**
     * @param list<\Beacon\Data\FindingEvidence> $evidence
     */
    protected function blocked(ConfigurationCatalog $catalog, array $evidence): ?Finding
    {
        if ($catalog->setting('logging.level')->state !== ConfigurationState::Invalid) {
            return null;
        }

        return $this->fail(
            $evidence,
            'The default logging channel level is not a recognised value.',
            'Set the logging channel level to a recognised level, or leave it unset to use Laravel\'s default.',
        );
    }
}
