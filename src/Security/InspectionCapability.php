<?php

declare(strict_types=1);

namespace Beacon\Security;

/**
 * The read-only inspection capabilities Beacon defines.
 *
 * Defining a capability grants nothing: it must also be allowed in
 * "beacon.security.allowed_capabilities".
 */
enum InspectionCapability: string
{
    case Application = 'application';
    case Environment = 'environment';
    case Routes = 'routes';
    case Config = 'config';
    case Database = 'database';
    case Migrations = 'migrations';
    case Exceptions = 'exceptions';
    case Queue = 'queue';
    case Health = 'health';

    public function capability(): Capability
    {
        return Capability::read($this->value);
    }

    public function description(): string
    {
        return match ($this) {
            self::Application => 'Application metadata such as Laravel and PHP versions and the application name.',
            self::Environment => 'Environment metadata and unsafe-configuration findings, without secret values.',
            self::Routes => 'Registered routes, HTTP methods, middleware and route metadata.',
            self::Config => 'Approved configuration metadata, without credentials or secrets.',
            self::Database => 'Database connection status and schema metadata, without credentials.',
            self::Migrations => 'Migration status, history and deterministic risk analysis.',
            self::Exceptions => 'Recent exceptions in a sanitized, structured form.',
            self::Queue => 'Queue and failed-job metadata.',
            self::Health => 'Normalized application and infrastructure health results.',
        };
    }
}
