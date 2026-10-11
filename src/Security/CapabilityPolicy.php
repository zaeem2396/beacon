<?php

declare(strict_types=1);

namespace Beacon\Security;

use Beacon\Core\BeaconConfig;

/**
 * Default-deny capability rules, applied in order:
 *
 * 1. Mutating capabilities are always denied (Beacon v0.x is read-only).
 * 2. Capabilities missing from the {@see CapabilityRegistry} are denied.
 * 3. Registered capabilities are denied unless explicitly allowed in
 *    "beacon.security.allowed_capabilities" ("*" allows every registered one).
 *
 * The principal is accepted so per-client grants can be added later; today
 * every authenticated principal shares the configured allowlist.
 */
final class CapabilityPolicy
{
    public function __construct(
        private readonly BeaconConfig $config,
        private readonly CapabilityRegistry $registry,
    ) {
    }

    public function evaluate(Principal $principal, Capability $capability): AuthorizationDecision
    {
        if (! $capability->isReadOnly()) {
            return AuthorizationDecision::deny('Beacon is read-only; mutating capabilities are not available.');
        }

        if (! $this->registry->has($capability)) {
            return AuthorizationDecision::deny("Capability [{$capability}] is not registered.");
        }

        $allowed = $this->config->allowedCapabilities();

        if (in_array('*', $allowed, true) || in_array($capability->name, $allowed, true)) {
            return AuthorizationDecision::allow();
        }

        return AuthorizationDecision::deny("Capability [{$capability}] is not allowed.");
    }
}
