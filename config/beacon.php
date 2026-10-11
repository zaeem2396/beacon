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
    */

    'mcp' => [
        'enabled' => env('BEACON_MCP_ENABLED', false),

        'allowed_environments' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BEACON_MCP_ENVIRONMENTS', 'local')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Token used to authenticate MCP clients. MCP access is refused while the
    | token is missing or shorter than 32 characters. Generate one with:
    |
    |     php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    |
    */

    'auth' => [
        'token' => env('BEACON_MCP_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Capabilities an AI client may use, e.g. ["application", "routes"], or
    | ["*"] for every registered read capability. Beacon v0.x is read-only:
    | mutating capabilities are always denied and cannot be enabled here.
    |
    */

    'security' => [
        'allowed_capabilities' => ['*'],
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
    ],

];
