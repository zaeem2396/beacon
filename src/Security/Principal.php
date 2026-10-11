<?php

declare(strict_types=1);

namespace Beacon\Security;

/**
 * An authenticated Beacon client.
 *
 * Carries identity only, never the credential itself. Authorization decisions
 * receive the principal so per-client permissions can be added later.
 */
final readonly class Principal
{
    public const LOCAL_METHOD = 'local-process';

    public function __construct(
        public string $id,
        public string $method,
    ) {
    }

    /**
     * A caller running on the same machine as the application (stdio, Artisan),
     * trusted through operating-system access rather than a credential.
     */
    public static function local(): self
    {
        return new self('local', self::LOCAL_METHOD);
    }
}
