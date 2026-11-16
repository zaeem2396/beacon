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
    case SchemaSnapshot = 'schema_snapshot';
    case SchemaCompare = 'schema_compare';
    case Runtime = 'runtime';
    case Exceptions = 'exceptions';
    case ErrorAggregate = 'error_aggregate';
    case ErrorSummary = 'error_summary';
    case Logs = 'logs';
    case Queue = 'queue';
    case FailedJobs = 'failed_jobs';
    case Scheduler = 'scheduler';
    case Requests = 'requests';
    case RequestSummary = 'request_summary';
    case SlowRequests = 'slow_requests';
    case SlowRequestSummary = 'slow_request_summary';
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
            self::SchemaSnapshot => 'Schema snapshots, without table rows or credentials.',
            self::SchemaCompare => 'Structural schema comparison, without reading table rows.',
            self::Runtime => 'PHP and Laravel runtime facts and allowlisted drivers, without credentials.',
            self::Exceptions => 'Recent exceptions in a sanitized, structured form.',
            self::ErrorAggregate => 'Grouped exceptions for the inspected log window.',
            self::ErrorSummary => 'Exception summary metrics for the inspected log window.',
            self::Logs => 'Structured summaries of supported file log channels.',
            self::Queue => 'Queue configuration and safely observable backend state.',
            self::FailedJobs => 'Failed job metadata without payloads.',
            self::Scheduler => 'Registered scheduled task definitions, without executing tasks.',
            self::Requests => 'Recent HTTP request observations, without bodies, cookies, or credentials.',
            self::RequestSummary => 'Request summary metrics for the inspected storage window.',
            self::SlowRequests => 'Recorded HTTP requests that exceeded configured duration thresholds.',
            self::SlowRequestSummary => 'Endpoint latency summaries for the inspected request window.',
            self::Health => 'Normalized application and infrastructure health results.',
        };
    }
}
