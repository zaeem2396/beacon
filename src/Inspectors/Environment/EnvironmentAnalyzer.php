<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Environment;

use Beacon\Contracts\Analyzer;
use Beacon\Contracts\Data;
use Beacon\Data\EnvironmentFacts;
use Beacon\Data\EnvironmentInspection;
use Beacon\Data\Finding;
use Beacon\Inspectors\Environment\Checks\AppKeyConfigured;
use Beacon\Inspectors\Environment\Checks\AppUrlSchemeInProduction;
use Beacon\Inspectors\Environment\Checks\DebugEnabledInProduction;
use Beacon\Inspectors\Environment\Checks\SessionHttpOnlyInProduction;
use Beacon\Inspectors\Environment\Checks\SessionSameSiteInProduction;
use Beacon\Inspectors\Environment\Checks\SessionSecureCookieInProduction;

/**
 * Runs the environment checks in a fixed order.
 *
 * The analyzer sees only the facts the inspector collected. Adding a check
 * means adding one class to {@see self::checks()} and nothing else.
 *
 * @implements Analyzer<EnvironmentFacts, EnvironmentInspection>
 */
final class EnvironmentAnalyzer implements Analyzer
{
    public function analyze(Data $subject): Data
    {
        if (! $subject instanceof EnvironmentFacts) {
            throw new \InvalidArgumentException('Environment analysis requires environment facts.');
        }

        return new EnvironmentInspection($subject, array_map(
            static fn (EnvironmentCheck $check): Finding => $check->evaluate($subject),
            $this->checks(),
        ));
    }

    /**
     * @return list<EnvironmentCheck>
     */
    public function checks(): array
    {
        return [
            new DebugEnabledInProduction(),
            new AppKeyConfigured(),
            new AppUrlSchemeInProduction(),
            new SessionSecureCookieInProduction(),
            new SessionHttpOnlyInProduction(),
            new SessionSameSiteInProduction(),
        ];
    }
}
