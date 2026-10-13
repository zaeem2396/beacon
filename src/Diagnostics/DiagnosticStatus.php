<?php

declare(strict_types=1);

namespace Beacon\Diagnostics;

/**
 * Outcome of a single diagnostic check.
 */
enum DiagnosticStatus: string
{
    /** Working as intended. */
    case Pass = 'pass';

    /** Working, but worth reviewing. */
    case Warn = 'warn';

    /** Intentionally switched off by configuration. */
    case Disabled = 'disabled';

    /** Not part of this Beacon version yet. */
    case NotImplemented = 'not_implemented';

    /** Configuration prevents the feature from working; fixable by the user. */
    case Misconfigured = 'misconfigured';

    /** Something is broken or the check could not run. */
    case Fail = 'fail';
}
