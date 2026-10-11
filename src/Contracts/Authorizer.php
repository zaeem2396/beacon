<?php

declare(strict_types=1);

namespace Beacon\Contracts;

use Beacon\Exceptions\AuthorizationException;
use Beacon\Security\AuthorizationDecision;
use Beacon\Security\Capability;
use Beacon\Security\Principal;

/**
 * Decides what an already authenticated {@see Principal} may do. Every
 * capability is denied unless it is read-only, registered and allowed.
 */
interface Authorizer
{
    public function decide(Principal $principal, Capability $capability): AuthorizationDecision;

    /**
     * @throws AuthorizationException
     */
    public function authorize(Principal $principal, Capability $capability): void;
}
