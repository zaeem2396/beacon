<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Beacon
    |--------------------------------------------------------------------------
    |
    | Master switch. When disabled, every Beacon capability is denied,
    | regardless of how MCP or individual inspectors are configured.
    |
    */

    'enabled' => env('BEACON_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | MCP
    |--------------------------------------------------------------------------
    |
    | The MCP interface is disabled by default. It only becomes reachable when
    | it is explicitly enabled, an authentication token is configured, and
    | the current environment is listed in "allowed_environments".
    |
    | Production is intentionally absent from the default list: exposing
    | Beacon over MCP in production must be an explicit decision.
    |
    | Requires the laravel/mcp package. The server is always available over
    | the local stdio transport ("php artisan mcp:start beacon"), which is
    | protected by operating-system access to the application.
    |
    | The HTTP transport is a separate opt-in. Every HTTP request must carry
    | the token as "Authorization: Bearer <token>" and, unless disabled
    | below, arrive over HTTPS.
    |
    */

    'mcp' => [
        'enabled' => env('BEACON_MCP_ENABLED', false),

        'allowed_environments' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BEACON_MCP_ENVIRONMENTS', 'local')),
        ))),

        'http' => [
            'enabled' => env('BEACON_MCP_HTTP_ENABLED', false),
            'path' => env('BEACON_MCP_HTTP_PATH', 'beacon/mcp'),
            'require_https' => env('BEACON_MCP_HTTP_REQUIRE_HTTPS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Bearer token HTTP clients must present. MCP access is refused while the
    | token is missing or shorter than 32 characters. During rotation, the
    | previous token may be set to keep it valid until clients are updated.
    | Never commit real tokens. Generate one with:
    |
    |     php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    |
    */

    'auth' => [
        'token' => env('BEACON_MCP_TOKEN'),
        'previous_token' => env('BEACON_MCP_PREVIOUS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Capabilities an authenticated client may use. Nothing is allowed by
    | default. List capabilities explicitly, e.g.
    | BEACON_ALLOWED_CAPABILITIES=application,routes, or use "*" for every
    | registered capability.
    |
    | Available: application, environment, routes, config, database, schema,
    | migrations, migration_risk, schema_snapshot, schema_compare, runtime,
    | exceptions, error_aggregate, error_summary, logs, queue, failed_jobs,
    | scheduler, requests, request_summary, slow_requests, slow_request_summary,
    | investigation_context, health.
    | Unregistered names are ignored.
    | Beacon v0.x is read-only: mutating capabilities are always denied and
    | cannot be enabled here.
    |
    */

    'security' => [
        'allowed_capabilities' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BEACON_ALLOWED_CAPABILITIES', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Beacon always redacts keys containing terms such as "password",
    | "secret", "token", "key", "credential", "cookie" or "authorization",
    | as well as values that look like secrets (private keys, credentials
    | embedded in URLs, bearer tokens, JWTs, ...). These built-in rules cannot
    | be disabled.
    |
    | List additional application-specific keys to redact here. Entries are
    | matched case-insensitively within any key segment, ignoring separators
    | ("iban" matches "customer_iban"). Dotted entries such as "services.acme"
    | redact that configuration path and everything beneath it.
    |
    */

    'redaction' => [
        'additional_keys' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database status
    |--------------------------------------------------------------------------
    |
    | Used only when database status is requested. The timeout is seconds,
    | from 1 to 5. Callers cannot change it. This does not enable the health
    | database probe.
    |
    */

    'database' => [
        'timeout_seconds' => env('BEACON_DATABASE_TIMEOUT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema inspection
    |--------------------------------------------------------------------------
    |
    | Used only when schema inspection is requested. The timeout is seconds,
    | from 1 to 5. connections lists Laravel connection names in addition to
    | the default. exclude lists exact table or view names, or a prefix
    | ending in *. Callers cannot change this list or supply SQL.
    |
    */

    'schema' => [
        'timeout_seconds' => env('BEACON_SCHEMA_TIMEOUT', 2),
        'connections' => array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) env('BEACON_SCHEMA_CONNECTIONS', '')),
        ), static fn (string $name): bool => $name !== '')),
        'exclude' => array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) env('BEACON_SCHEMA_EXCLUDE', '')),
        ), static fn (string $name): bool => $name !== '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration inspection
    |--------------------------------------------------------------------------
    |
    | Used only when migration inspection is requested. The timeout is seconds,
    | from 1 to 5. connections lists Laravel connection names in addition to
    | the default. Callers cannot choose a filesystem path or run migrations.
    |
    */

    'migrations' => [
        'timeout_seconds' => env('BEACON_MIGRATIONS_TIMEOUT', 2),
        'connections' => array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) env('BEACON_MIGRATIONS_CONNECTIONS', '')),
        ), static fn (string $name): bool => $name !== '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration risk analysis
    |--------------------------------------------------------------------------
    |
    | Used only when migration risk analysis is requested. The timeout bounds
    | the repository read, from 1 to 5 seconds. connections lists Laravel
    | connection names in addition to the default. Schema metadata is used
    | only when the schema capability is also allowed. Callers cannot supply
    | PHP, SQL, or a filesystem path.
    |
    */

    'migration_risk' => [
        'timeout_seconds' => env('BEACON_MIGRATION_RISK_TIMEOUT', 2),
        'connections' => array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) env('BEACON_MIGRATION_RISK_CONNECTIONS', '')),
        ), static fn (string $name): bool => $name !== '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health probes
    |--------------------------------------------------------------------------
    |
    | Dependency probes are off by default. They run only when a health report
    | is requested and the probe is enabled. They do not run on a normal HTTP
    | request. Database timeout is seconds, from 1 to 5.
    |
    */

    'health' => [
        'database' => [
            'enabled' => env('BEACON_HEALTH_DATABASE', false),
            'timeout_seconds' => env('BEACON_HEALTH_DATABASE_TIMEOUT', 2),
        ],
        'cache' => [
            'enabled' => env('BEACON_HEALTH_CACHE', false),
        ],
        'queue' => [
            'enabled' => env('BEACON_HEALTH_QUEUE', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request intelligence
    |--------------------------------------------------------------------------
    |
    | HTTP request collection is off by default. Enable it only after publishing
    | and running the Beacon requests migration. Beacon does not migrate for you.
    | retention_hours bounds cleanup for php artisan beacon:requests-prune.
    |
    */

    'requests' => [
        'collection' => [
            'enabled' => env('BEACON_REQUESTS_COLLECTION', false),
        ],
        'connection' => env('BEACON_REQUESTS_CONNECTION'),
        'retention_hours' => env('BEACON_REQUESTS_RETENTION_HOURS', 168),
        'middleware' => env('BEACON_REQUESTS_MIDDLEWARE', true),
        'slow' => [
            'threshold_ms' => env('BEACON_SLOW_REQUEST_THRESHOLD_MS', 1000),
            'window_minutes' => env('BEACON_SLOW_REQUEST_WINDOW_MINUTES', 60),
            'minimum_samples' => env('BEACON_SLOW_REQUEST_MINIMUM_SAMPLES', 5),
            'default_limit' => env('BEACON_SLOW_REQUEST_LIMIT', 25),
            'max_endpoints' => env('BEACON_SLOW_REQUEST_MAX_ENDPOINTS', 25),
            // Route name, URI, or "METHOD /uri" keys mapped to milliseconds.
            'endpoints' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI investigation context
    |--------------------------------------------------------------------------
    |
    | Combines existing Beacon evidence for a bounded investigation. Defaults
    | keep output small. Callers cannot raise limits above these ceilings.
    | Deployment and configuration-change history are not fabricated when
    | unavailable.
    |
    */

    'investigation' => [
        'window_minutes' => env('BEACON_INVESTIGATION_WINDOW_MINUTES', 60),
        'max_exceptions' => env('BEACON_INVESTIGATION_MAX_EXCEPTIONS', 25),
        'max_exception_samples' => env('BEACON_INVESTIGATION_MAX_EXCEPTION_SAMPLES', 5),
        'max_requests' => env('BEACON_INVESTIGATION_MAX_REQUESTS', 25),
        'max_slow_requests' => env('BEACON_INVESTIGATION_MAX_SLOW_REQUESTS', 25),
        'max_logs' => env('BEACON_INVESTIGATION_MAX_LOGS', 25),
        'max_failed_jobs' => env('BEACON_INVESTIGATION_MAX_FAILED_JOBS', 25),
        'max_timeline_events' => env('BEACON_INVESTIGATION_MAX_TIMELINE', 50),
        'max_leads' => env('BEACON_INVESTIGATION_MAX_LEADS', 10),
        'max_message_length' => env('BEACON_INVESTIGATION_MAX_MESSAGE_LENGTH', 240),
        'max_stack_frames' => env('BEACON_INVESTIGATION_MAX_STACK_FRAMES', 5),
        'max_total_evidence' => env('BEACON_INVESTIGATION_MAX_TOTAL', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inspectors
    |--------------------------------------------------------------------------
    |
    | Enable or disable individual inspectors. Inspectors not listed here are
    | enabled once registered.
    |
    */

    'inspectors' => [
        'application' => ['enabled' => true],
        'environment' => ['enabled' => true],
        'routes' => ['enabled' => true],
        'config' => [
            'enabled' => true,
            // Documented interest only. Configuration inspection uses a code allowlist
            // and does not treat this list as paths a caller may request.
            'namespaces' => [
                'app',
                'cache',
                'database',
                'queue',
                'session',
                'filesystems',
                'mail',
                'logging',
            ],
        ],
        'database' => ['enabled' => true],
        'schema' => ['enabled' => true],
        'migrations' => ['enabled' => true],
        'migration_risk' => ['enabled' => true],
        'schema_snapshot' => ['enabled' => true],
        'schema_compare' => ['enabled' => true],
        'runtime' => ['enabled' => true],
        'exceptions' => ['enabled' => true],
        'error_aggregate' => ['enabled' => true],
        'error_summary' => ['enabled' => true],
        'logs' => ['enabled' => true],
        'queue' => ['enabled' => true],
        'failed_jobs' => ['enabled' => true],
        'scheduler' => ['enabled' => true],
        'requests' => ['enabled' => true],
        'request_summary' => ['enabled' => true],
        'slow_requests' => ['enabled' => true],
        'slow_request_summary' => ['enabled' => true],
        'investigation_context' => ['enabled' => true],
        'health' => ['enabled' => true],
    ],

];
