<?php

declare(strict_types=1);

namespace Beacon\Security;

final readonly class AuthorizationDecision
{
    private function __construct(
        public bool $allowed,
        public string $reason,
    ) {
    }

    public static function allow(string $reason = 'Allowed.'): self
    {
        return new self(true, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
