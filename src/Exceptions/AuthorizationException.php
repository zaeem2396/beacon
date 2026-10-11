<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

use Beacon\Security\AuthorizationDecision;
use Beacon\Security\Capability;

final class AuthorizationException extends \RuntimeException implements BeaconException
{
    public static function denied(Capability $capability, AuthorizationDecision $decision): self
    {
        return new self("Capability [{$capability}] denied: {$decision->reason}");
    }
}
