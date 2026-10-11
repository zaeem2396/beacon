<?php

declare(strict_types=1);

namespace Beacon\Security;

final readonly class AuthenticationResult
{
    private function __construct(
        public ?Principal $principal,
        public ?AuthenticationFailure $failure,
    ) {
    }

    public static function success(Principal $principal): self
    {
        return new self($principal, null);
    }

    public static function failure(AuthenticationFailure $failure): self
    {
        return new self(null, $failure);
    }

    /**
     * @phpstan-assert-if-true Principal $this->principal
     *
     * @phpstan-assert-if-false AuthenticationFailure $this->failure
     */
    public function authenticated(): bool
    {
        return $this->principal !== null;
    }
}
