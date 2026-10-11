<?php

declare(strict_types=1);

namespace Beacon\Security;

use Beacon\Contracts\Authorizer;
use Beacon\Core\BeaconConfig;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Exceptions\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;

final class CapabilityAuthorizer implements Authorizer
{
    public function __construct(
        private readonly BeaconConfig $config,
        private readonly CapabilityPolicy $policy,
        private readonly ?Dispatcher $events = null,
    ) {
    }

    public function decide(Principal $principal, Capability $capability): AuthorizationDecision
    {
        $decision = $this->config->enabled()
            ? $this->policy->evaluate($principal, $capability)
            : AuthorizationDecision::deny('Beacon is disabled.');

        $this->events?->dispatch(new AuthorizationEvaluated(
            $principal->id,
            $principal->method,
            (string) $capability,
            $decision->allowed,
            $decision->reason,
        ));

        return $decision;
    }

    public function authorize(Principal $principal, Capability $capability): void
    {
        $decision = $this->decide($principal, $capability);

        if (! $decision->allowed) {
            throw AuthorizationException::denied($capability, $decision);
        }
    }
}
