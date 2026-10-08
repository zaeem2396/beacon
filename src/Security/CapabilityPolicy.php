<?php

declare(strict_types=1);

namespace Beacon\Security;

use Beacon\Core\BeaconConfig;

/**
 * Decides whether a capability may be used at all.
 *
 * Beacon v0.x is read-only: mutating capabilities are always denied.
 */
final class CapabilityPolicy
{
    public function __construct(
        private readonly BeaconConfig $config,
    ) {
    }

    public function evaluate(Capability $capability): AuthorizationDecision
    {
        if (! $capability->isReadOnly()) {
            return AuthorizationDecision::deny('Beacon is read-only; mutating capabilities are not available.');
        }

        $allowed = $this->config->allowedCapabilities();

        if (in_array('*', $allowed, true) || in_array($capability->name, $allowed, true)) {
            return AuthorizationDecision::allow();
        }

        return AuthorizationDecision::deny(
            "Capability [{$capability->name}] is not listed in beacon.security.allowed_capabilities.",
        );
    }
}
