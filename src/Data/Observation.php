<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * A closed set of facts a finding may cite.
 *
 * Observations are classifications, never copied configuration values, so
 * evidence cannot carry a secret that was sitting in the setting being checked.
 */
enum Observation: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Present = 'present';
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Sensitive = 'sensitive';
    case Http = 'http';
    case Https = 'https';
    case Production = 'production';
    case NotProduction = 'not_production';
    case Lax = 'lax';
    case Strict = 'strict';
    case None = 'none';
}
