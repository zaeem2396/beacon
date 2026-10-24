<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * What an environment inspection may say about one allowlisted setting.
 *
 * Unsafe is not a state. A setting becomes a concern only through a finding,
 * which has to name the check and the evidence.
 */
enum ConfigurationState: string
{
    case Present = 'present';
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Sensitive = 'sensitive';
}
