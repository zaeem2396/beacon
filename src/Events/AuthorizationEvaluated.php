<?php

declare(strict_types=1);

namespace Beacon\Events;

/**
 * Dispatched for every capability authorization decision, so applications can
 * audit Beacon access with their own listeners and log channels.
 *
 * Contains identifiers and the decision only, never credentials or data.
 */
final readonly class AuthorizationEvaluated
{
    public function __construct(
        public string $principal,
        public string $authenticationMethod,
        public string $capability,
        public bool $allowed,
        public string $reason,
    ) {
    }
}
