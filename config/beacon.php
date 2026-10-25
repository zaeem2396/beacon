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
    | Available: application, environment, routes, config, database,
    | migrations, exceptions, queue, health. Unregistered names are ignored.
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
        'migrations' => ['enabled' => true],
        'exceptions' => ['enabled' => true],
        'queue' => ['enabled' => true],
        'health' => ['enabled' => true],
    ],

];
