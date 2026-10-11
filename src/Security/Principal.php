<?php

declare(strict_types=1);

namespace Beacon\Security;

/**
 * An authenticated Beacon client.
 *
 * Carries identity only, never the credential itself. Future per-client
 * permissions can be resolved from the identifier.
 */
final readonly class Principal
{
    public function __construct(
        public string $id,
        public string $method,
    ) {
    }
}
