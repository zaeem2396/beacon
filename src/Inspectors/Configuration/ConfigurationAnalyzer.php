<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration;

use Beacon\Contracts\Analyzer;
use Beacon\Contracts\Data;
use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\Finding;
use Beacon\Inspectors\Configuration\Checks\AppCipher;
use Beacon\Inspectors\Configuration\Checks\AppMaintenanceDriver;
use Beacon\Inspectors\Configuration\Checks\CacheStore;
use Beacon\Inspectors\Configuration\Checks\DatabaseConnection;
use Beacon\Inspectors\Configuration\Checks\FilesystemDisk;
use Beacon\Inspectors\Configuration\Checks\LoggingChannel;
use Beacon\Inspectors\Configuration\Checks\MailMailer;
use Beacon\Inspectors\Configuration\Checks\QueueConnection;
use Beacon\Inspectors\Configuration\Checks\SessionDriver;
use Beacon\Inspectors\Configuration\Checks\SessionSecureCookie;

/**
 * Runs the configuration checks in a fixed order.
 *
 * The analyzer sees only the catalog the inspector collected. Adding a check
 * means adding one class to {@see self::checks()} and nothing else.
 *
 * @implements Analyzer<ConfigurationCatalog, ConfigurationAnalysis>
 */
final class ConfigurationAnalyzer implements Analyzer
{
    public function analyze(Data $subject): Data
    {
        if (! $subject instanceof ConfigurationCatalog) {
            throw new \InvalidArgumentException('Configuration analysis requires a configuration catalog.');
        }

        return new ConfigurationAnalysis(array_map(
            static fn (ConfigurationCheck $check): Finding => $check->evaluate($subject),
            $this->checks(),
        ));
    }

    /**
     * @return list<ConfigurationCheck>
     */
    public function checks(): array
    {
        return [
            new AppCipher(),
            new AppMaintenanceDriver(),
            new DatabaseConnection(),
            new CacheStore(),
            new QueueConnection(),
            new MailMailer(),
            new FilesystemDisk(),
            new LoggingChannel(),
            new SessionDriver(),
            new SessionSecureCookie(),
        ];
    }
}
