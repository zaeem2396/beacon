<?php

declare(strict_types=1);

namespace Beacon\Contracts;

use Beacon\Exceptions\AuthorizationException;
use Beacon\Security\AuthorizationDecision;
use Beacon\Security\Capability;

interface Authorizer
{
    public function decide(Capability $capability): AuthorizationDecision;

    /**
     * @throws AuthorizationException
     */
    public function authorize(Capability $capability): void;
}
