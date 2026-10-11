<?php

declare(strict_types=1);

namespace Beacon\Security;

enum AuthenticationFailure: string
{
    /** No usable credentials are configured, so nobody can authenticate. */
    case NotConfigured = 'not_configured';

    /** The request carries no credentials. */
    case Missing = 'missing';

    /** The request carries credentials in an unsupported form or place. */
    case Malformed = 'malformed';

    /** The credentials are well-formed but do not match. */
    case Invalid = 'invalid';
}
