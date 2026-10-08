<?php

declare(strict_types=1);

namespace Beacon\Security;

use Beacon\Contracts\Authorizer;
use Beacon\Core\BeaconConfig;
use Beacon\Exceptions\AuthorizationException;

final class CapabilityAuthorizer implements Authorizer
{
    public function __construct(
        private readonly BeaconConfig $config,
        private readonly CapabilityPolicy $policy,
    ) {
    }

    public function decide(Capability $capability): AuthorizationDecision
    {
        if (! $this->config->enabled()) {
            return AuthorizationDecision::deny('Beacon is disabled.');
        }

        return $this->policy->evaluate($capability);
    }

    public function authorize(Capability $capability): void
    {
        $decision = $this->decide($capability);

        if (! $decision->allowed) {
            throw AuthorizationException::denied($capability, $decision);
        }
    }
}
