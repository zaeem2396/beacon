<?php

declare(strict_types=1);

namespace Beacon\Contracts;

use Beacon\Security\AuthenticationResult;
use Illuminate\Http\Request;

/**
 * Establishes who is calling Beacon over a remote transport.
 *
 * Authentication only identifies the caller. What the caller may do is
 * decided separately by the {@see Authorizer}.
 */
interface Authenticator
{
    public function authenticate(Request $request): AuthenticationResult;
}
