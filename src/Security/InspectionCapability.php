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
    case Schema = 'schema';
    case Migrations = 'migrations';
    case MigrationRisk = 'migration_risk';
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
            self::Database => 'Database connection status, without credentials.',
            self::Schema => 'Database schema metadata, without credentials or table contents.',
            self::Migrations => 'Migration files and repository history, without executing migrations.',
            self::MigrationRisk => 'Static migration risk findings, without executing migrations.',
            self::Exceptions => 'Recent exceptions in a sanitized, structured form.',
            self::Queue => 'Queue and failed-job metadata.',
            self::Health => 'Normalized application and infrastructure health results.',
        };
    }
}
