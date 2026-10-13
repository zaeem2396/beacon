# Laravel Beacon

Laravel Beacon exposes safe, structured production information about a Laravel application to AI agents (Claude, Cursor and other MCP clients) so they can investigate production problems using real application evidence.

> **Status:** early development (v0.1). The package foundation, the MCP server foundation, MCP authentication, the read-only security model and the CLI foundation (`beacon`, `beacon:install`, `beacon:check`) are in place. The first inspector and MCP tool, [`beacon.get_application_info`](#beaconget_application_info), are available. Environment, route, configuration and health inspection are next. See [ROADMAP.md](ROADMAP.md).

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

```bash
composer require zaeem2396/beacon
```

The service provider is registered automatically through package discovery. Then run the installer and the diagnostics:

```bash
php artisan beacon:install
php artisan beacon:check
```

See [Artisan commands](#artisan-commands) for details.

## Artisan commands

| Command                      | Purpose                                                                 |
| ---------------------------- | ----------------------------------------------------------------------- |
| `php artisan beacon`         | Installed version, whether Beacon and MCP are active, available commands |
| `php artisan beacon:install` | Publish `config/beacon.php` and explain the remaining manual setup      |
| `php artisan beacon:check`   | Diagnose the installation and configuration (`--json` for automation)   |

Every command supports `--help`. None of them print tokens or other secret configuration values.

### `beacon:install`

```bash
php artisan beacon:install
```

- Publishes `config/beacon.php` (the `beacon-config` publish tag) if it does not exist yet.
- **Never overwrites an existing file** and is safe to run repeatedly. To replace your configuration deliberately, run `php artisan vendor:publish --tag=beacon-config --force`.
- **Does not edit `.env`, generate tokens, enable MCP or expose an HTTP endpoint.** It prints the variables you need to set yourself:

```text
   INFO  Published config/beacon.php.

   INFO  Beacon is installed. MCP stays disabled until you enable it.

  To enable MCP, add the following to your .env file:

    BEACON_MCP_ENABLED=true
    BEACON_MCP_TOKEN=<random token of at least 32 characters>
    BEACON_MCP_ENVIRONMENTS=local
    BEACON_ALLOWED_CAPABILITIES=<capabilities to allow, e.g. application,routes>
```

### `beacon:check`

```bash
php artisan beacon:check
php artisan beacon:check --json
```

`beacon:check` runs read-only self-diagnostics. It does not connect to the database or any other service, does not change any state, and works in any environment.

| Check id                | What it verifies                                                                         |
| ----------------------- | ---------------------------------------------------------------------------------------- |
| `package`               | Beacon's service provider is registered; reports the installed version                    |
| `config`                | The `beacon` configuration exists and has valid types and an HTTP path (key names only) |
| `application`           | Warns when `APP_DEBUG` is on in production                                               |
| `mcp`                   | Whether MCP is enabled, has a usable token and is allowed in the current environment     |
| `mcp.sdk`               | `laravel/mcp` is installed and its service provider is registered                        |
| `mcp.server`            | The `beacon` server is registered with `laravel/mcp` when MCP is available                |
| `mcp.http`              | The optional HTTP transport: disabled, registered, and whether HTTPS is required          |
| `auth`                  | A token of sufficient length is configured; reports token rotation; never prints tokens   |
| `security.read_only`    | Mutating capabilities are denied by the capability policy                                 |
| `security.capabilities` | The capability allowlist: default deny, `*`, or unregistered names                        |
| `security.redaction`    | The built-in redaction rules are active                                                   |
| `inspectors`            | Which inspectors are enabled; `disabled` when every inspector is turned off               |
| `mcp.tools`             | How many MCP tools are registered; `disabled` when there are none (no `laravel/mcp`)      |

Each check reports one status:

| Status            | Meaning                                                                    | Affects exit code |
| ----------------- | -------------------------------------------------------------------------- | ----------------- |
| `pass`            | Works as intended                                                          | no                |
| `warn`            | Works, but should be reviewed (e.g. plain HTTP, token rotation, `*`)       | no                |
| `disabled`        | Intentionally off (e.g. MCP disabled by default)                           | no                |
| `not_implemented` | A feature Beacon does not provide yet                                      | no                |
| `misconfigured`   | Configuration prevents the feature from working; fix your configuration    | exit code `1`     |
| `fail`            | Something is broken and is not a configuration problem                     | exit code `2`     |

#### Exit codes

| Code | Meaning                                                                    |
| ---- | -------------------------------------------------------------------------- |
| `0`  | Every check passed, or is a warning, disabled or not implemented           |
| `1`  | At least one check is `misconfigured` (and none failed)                    |
| `2`  | At least one check failed, or the diagnostics could not run at all         |

For example, to fail a deployment pipeline on configuration problems:

```bash
php artisan beacon:check --json > beacon-check.json || exit $?
```

#### JSON output

With `--json`, stdout contains exactly one JSON document and nothing else (no colors, no progress output). The schema is versioned by `schema_version`; fields may be added in later versions of schema 1, but existing fields will not be removed or change meaning.

```json
{
    "schema_version": 1,
    "beacon_version": "v0.1.0",
    "status": "misconfigured",
    "exit_code": 1,
    "summary": {
        "pass": 9,
        "warn": 0,
        "disabled": 2,
        "not_implemented": 0,
        "misconfigured": 2,
        "fail": 0
    },
    "checks": [
        {
            "id": "auth",
            "name": "Authentication",
            "status": "misconfigured",
            "message": "BEACON_MCP_TOKEN is shorter than 32 characters and is rejected.",
            "hint": "Generate a token with: php -r \"echo bin2hex(random_bytes(32)), PHP_EOL;\" and set BEACON_MCP_TOKEN."
        }
    ]
}
```

| Field            | Type            | Description                                                                 |
| ---------------- | --------------- | --------------------------------------------------------------------------- |
| `schema_version` | integer         | Version of this schema                                                      |
| `beacon_version` | string          | Installed Beacon version from Composer metadata, or `unknown`               |
| `status`         | string          | Overall result: `ok`, `warn`, `misconfigured` or `fail`                     |
| `exit_code`      | integer         | The process exit code (see above)                                           |
| `summary`        | object          | Number of checks per status; every status is always present                |
| `checks`         | array           | One object per check, in a stable order                                     |
| `checks[].id`    | string          | Stable identifier; use it rather than `name` in automation                  |
| `checks[].name`  | string          | Human-readable name                                                         |
| `checks[].status`| string          | One of the check statuses above                                             |
| `checks[].message` | string        | What was found                                                              |
| `checks[].hint`  | string or null  | How to fix or improve it                                                    |

The output never contains token values, secret configuration values, environment dumps or exception messages. If a single check throws, it is reported as `fail` with a generic message. If the diagnostics cannot run at all, the command still prints a valid document with `"status": "fail"`, `"exit_code": 2` and an empty `checks` array, and writes the exception class (without its message) to stderr.

### Limitations

- `beacon:check` validates Beacon itself; it does not run inspectors or connect to your database, cache or queue. Use the [MCP tools](#mcp-tools) to inspect the application.
- The checks reflect the configuration of the process that runs them. With a cached configuration (`php artisan config:cache`), run `php artisan config:clear` after changing `.env`.
- There is no dedicated MCP management command; start the server with `php artisan mcp:start beacon` and verify it with `beacon:check`.

## Security defaults

Beacon is read-only and closed by default:

- **MCP is disabled.** It only becomes accessible when all of the following are true:
  - `BEACON_MCP_ENABLED=true`
  - `BEACON_MCP_TOKEN` is set to a token of at least 32 characters
  - the current environment is listed in `BEACON_MCP_ENVIRONMENTS` (default: `local`)
- **Production must be opted into explicitly**, e.g. `BEACON_MCP_ENVIRONMENTS=local,production`.
- **The HTTP transport is a separate opt-in** (`BEACON_MCP_HTTP_ENABLED=true`). Every HTTP request must present the token as a bearer token over HTTPS.
- **No capability is allowed.** Even an authenticated client can do nothing until you list capabilities in `BEACON_ALLOWED_CAPABILITIES`. See [Authorization and the read-only model](#authorization-and-the-read-only-model).
- **Mutating capabilities are always denied.** This cannot be changed through configuration.
- **Sensitive values are always redacted.** Keys such as `APP_KEY`, `DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, `Authorization` or `Cookie`, and values that look like secrets (private keys, credentials in URLs, bearer tokens, JWTs, cloud API keys), are replaced with `[REDACTED]`. You can add your own keys under `redaction.additional_keys`, but the built-in rules cannot be removed.

```dotenv
BEACON_ENABLED=true
BEACON_MCP_ENABLED=false
BEACON_MCP_TOKEN=
BEACON_MCP_PREVIOUS_TOKEN=
BEACON_MCP_ENVIRONMENTS=local
BEACON_MCP_HTTP_ENABLED=false
BEACON_MCP_HTTP_PATH=beacon/mcp
BEACON_MCP_HTTP_REQUIRE_HTTPS=true
BEACON_ALLOWED_CAPABILITIES=
```

## MCP server

Beacon's MCP server is built on Laravel's official [`laravel/mcp`](https://github.com/laravel/mcp) package, which is optional:

```bash
composer require laravel/mcp
```

`laravel/mcp` ^1.0 supports PHP 8.2+ with Laravel 11.45+, 12.41+ and 13. Without it, Beacon works normally and the MCP server is simply not registered.

### Enabling the server

The server is only registered when every MCP access condition from [Security defaults](#security-defaults) is met:

```dotenv
BEACON_MCP_ENABLED=true
BEACON_MCP_TOKEN=<at least 32 characters>
BEACON_MCP_ENVIRONMENTS=local
```

### Generating a token

Generate a random token of at least 32 characters and store it only in the environment (`.env`, your secret manager or your deployment platform), never in code, configuration files or version control:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Use a different token per environment. Beacon never logs the token or returns it in a response.

### Transports

Beacon supports two transports, and they authenticate differently.

| Transport | Enabled by                                   | Who can connect                                                          |
| --------- | -------------------------------------------- | ------------------------------------------------------------------------ |
| stdio     | MCP access conditions                        | Anyone who can run `php artisan` as the application's operating-system user |
| HTTP      | MCP access conditions + `BEACON_MCP_HTTP_ENABLED=true` | Clients presenting the bearer token over HTTPS                    |

#### stdio (local)

The client starts the server as a subprocess, so there is no network connection to authenticate. Access is controlled by the operating system: whoever can run `php artisan` can also read `.env`, and asking that user for the token would add no protection. Beacon therefore does not ask stdio clients for the token. Restrict who can log in to the machine and read the application directory instead.

Start the server with:

```bash
php artisan mcp:start beacon
```

For example, in Cursor's `.cursor/mcp.json`:

```json
{
    "mcpServers": {
        "laravel-beacon": {
            "command": "php",
            "args": ["artisan", "mcp:start", "beacon"]
        }
    }
}
```

If MCP is disabled or not configured, `mcp:start beacon` fails with "MCP Server with name [beacon] not found", and starting the server class directly throws `McpUnavailableException`.

#### HTTP (remote)

Enable it in addition to the MCP access conditions:

```dotenv
BEACON_MCP_HTTP_ENABLED=true
BEACON_MCP_HTTP_PATH=beacon/mcp
```

Beacon then registers `POST /beacon/mcp` (laravel/mcp's streamable HTTP transport). Every request, not just the `initialize` handshake, must send the token in the `Authorization` header:

```http
POST /beacon/mcp HTTP/1.1
Host: app.example.com
Authorization: Bearer <your token>
Content-Type: application/json
Accept: application/json, text/event-stream
```

For example, in Cursor's `.cursor/mcp.json`, reading the token from your shell environment rather than writing it into the file:

```json
{
    "mcpServers": {
        "laravel-beacon": {
            "url": "https://app.example.com/beacon/mcp",
            "headers": {
                "Authorization": "Bearer ${env:BEACON_MCP_TOKEN}"
            }
        }
    }
}
```

Responses:

| Situation                                                                     | Status |
| ----------------------------------------------------------------------------- | ------ |
| HTTP transport disabled, MCP not allowed, or no valid token configured         | `404`  |
| Request not made over HTTPS (while `BEACON_MCP_HTTP_REQUIRE_HTTPS=true`)      | `403`  |
| Malformed `Authorization` header, or a token in the query string              | `400`  |
| Missing or wrong token                                                        | `401`  |
| Valid token                                                                   | handled by the MCP server |

Error bodies are generic and never say which check failed beyond the standard `WWW-Authenticate` error code. Unauthenticated `GET` and `DELETE` requests also receive `401`, so the endpoint is not revealed to unauthenticated callers.

Rules:

- **Tokens are only accepted in the `Authorization` header.** A request with `access_token` in the query string is rejected, because URLs end up in access logs, proxies and browser history.
- **HTTPS is required.** Bearer tokens sent over plain HTTP can be intercepted. Set `BEACON_MCP_HTTP_REQUIRE_HTTPS=false` only for local development. Behind a load balancer or reverse proxy, configure Laravel's [trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies) so that HTTPS is detected.
- **Access is re-checked on every request.** If MCP is disabled or the token is removed, the endpoint answers `404` even if routes are cached.
- **No CORS headers, sessions or cookies.** The route is not in the `web` middleware group and Beacon adds no CORS configuration. It is meant for MCP clients, not browsers.
- **Public exposure without authentication is unsupported.** Beacon reveals application internals. There is no option to turn authentication off, and the endpoint cannot be registered without a valid token.

### Rotating the token

1. Generate a new token.
2. Set it as `BEACON_MCP_TOKEN` and move the old one to `BEACON_MCP_PREVIOUS_TOKEN`. Both are accepted.
3. Update every MCP client to the new token.
4. Remove `BEACON_MCP_PREVIOUS_TOKEN`. The old token is rejected from then on.

If your configuration is cached, run `php artisan config:cache` after each change. If a token may have leaked, skip the overlap: replace `BEACON_MCP_TOKEN` and leave `BEACON_MCP_PREVIOUS_TOKEN` empty.

### Custom authentication

Authentication is resolved through the `Beacon\Contracts\Authenticator` contract and is separate from capability authorization (`Beacon\Contracts\Authorizer`). An authenticator returns a `Principal` (an identifier, never the credential), which Beacon stores on the request under the `Beacon\Security\Principal` attribute. To use your own tokens, bind your own implementation:

```php
$this->app->singleton(\Beacon\Contracts\Authenticator::class, MyAuthenticator::class);
```

What an authenticated client may do is decided separately; see [Authorization and the read-only model](#authorization-and-the-read-only-model).

### Current limitations

- **One shared credential.** The bearer token identifies "a Beacon client", not a specific user or agent. Use the rotation steps above to revoke access.
- **Tokens do not expire.** They stay valid until you rotate them.
- **No built-in rate limiting.** A 32+ character random token cannot realistically be guessed, but you can add Laravel's `throttle` middleware in front of the endpoint if you need request limits.
- **No OAuth.** laravel/mcp's OAuth support requires Laravel Passport and user accounts, which Beacon does not assume.
- **One tool, no resources or prompts.** The server exposes [`beacon.get_application_info`](#beaconget_application_info). Further tools follow the [roadmap](ROADMAP.md).

## Authorization and the read-only model

**Authentication** establishes *who* is calling: the local process for stdio, or a bearer-token client for HTTP. **Authorization** decides *what* that caller may do. They are separate: `Beacon\Contracts\Authenticator` produces a `Principal`, and `Beacon\Contracts\Authorizer` decides whether that principal may use a capability.

### Capabilities

Every operation that exposes application information requires exactly one capability. Beacon defines these read-only capabilities (`Beacon\Security\InspectionCapability`):

| Capability    | Covers                                                        |
| ------------- | ------------------------------------------------------------- |
| `application` | Application metadata (Laravel and PHP versions, name)         |
| `environment` | Environment metadata and unsafe-configuration findings        |
| `routes`      | Routes, HTTP methods, middleware and route metadata           |
| `config`      | Selected configuration values, with secrets masked            |
| `database`    | Database status and schema metadata, without credentials      |
| `migrations`  | Migration status, history and risk analysis                   |
| `exceptions`  | Recent exceptions in sanitized form                           |
| `queue`       | Queue and failed-job metadata                                 |
| `health`      | Application and infrastructure health results                 |

`application` is used by [`beacon.get_application_info`](#beaconget_application_info). The others define permissions for upcoming inspectors and grant nothing yet.

### Default deny

A capability is allowed only when **all** of these hold:

1. It is **read-only**. Mutating capabilities are always denied and cannot even be registered.
2. It is **registered** in `Beacon\Security\CapabilityRegistry`. Unknown names (`shell`, `sql`, ...) are denied, even when listed in the configuration or declared by a tool.
3. It is **explicitly allowed**:

```dotenv
BEACON_ALLOWED_CAPABILITIES=application,routes,health
```

The default is empty, so nothing is allowed. `*` allows every registered capability. Beacon also denies everything while `BEACON_ENABLED=false`.

All authenticated clients currently share this allowlist. Authorization already receives the caller's `Principal`, so per-client grants can be added later without changing tools.

### Where authorization is enforced

- **MCP execution boundary.** `Beacon\Mcp\ExecutionGate` runs before any MCP method is handled:
  - `tools/call`, `resources/read`, `prompts/get` and `completion/complete` require a caller identity plus the target primitive's declared capability.
  - Describing methods (`initialize`, `ping`, the `*/list` methods) need no capability. Denied tools still appear in `tools/list` but cannot run.
  - Every other method is denied.
  - All denials return the same JSON-RPC error (`-32001`, "Permission denied."), with no reason, capability name or configuration detail.
- **Caller identity.** stdio callers are the local process. HTTP callers are only the `Principal` set by Beacon's authentication middleware. Client-supplied headers or metadata are never trusted, and a request that reached the server any other way has no identity, so nothing executes.
- **Inspections.** `Beacon::inspect($principal, $name)` requires a principal and authorizes the inspector's capability, for MCP and any future CLI alike.

Every decision dispatches a `Beacon\Events\AuthorizationEvaluated` event: principal id, authentication method, capability, outcome and reason, never credentials. Listen to it to write your own audit log. Beacon itself logs nothing and sends nothing anywhere.

### Sensitive data

- **Typed output only.** Inspectors return typed `Data` objects with an explicit set of fields, never whole configuration arrays or framework objects. Anything else cannot be normalized and is rejected.
- **Redacted payloads.** Every `Payload` is redacted by key and by value before it leaves the core.
- **Sanitized MCP responses.** Every MCP message, including error messages that laravel/mcp returns verbatim while `APP_DEBUG=true`, passes through `Beacon\Mcp\ResponseSanitizer`. It replaces any string that contains a recognisable secret, such as:
  - credentials in URLs
  - `password=` / `api_key:` style assignments
  - bearer and basic credentials
  - Laravel app keys, JWTs, private keys and cloud or API tokens
- **Whole-string replacement.** When a string contains a secret, the entire string becomes `[REDACTED]`. Some legitimate text (for example "token: expired") is redacted too; Beacon prefers losing a message to leaking a secret.

### Read-only guarantees and limitations

Beacon v0.1 only inspects and reports. MCP clients cannot, and Beacon provides no tools to:

- execute PHP, shell commands, Artisan commands or SQL
- modify configuration, files, database records or schema
- trigger deployments, clear caches or restart workers
- retry, delete or otherwise mutate queued jobs
- invoke operations that are not explicitly registered

"Read-only" is a property Beacon enforces on *capabilities*, not a guarantee about arbitrary code. Inspectors must use purpose-built, side-effect-free operations, and the label alone does not make code safe. Redaction is pattern-based and cannot recognise every possible secret, so only allow the capabilities you need.

### Adding MCP tools

Every MCP tool, resource and prompt must implement `Beacon\Contracts\RequiresCapability`. `McpRegistry` rejects anything that does not, and registering a tool never grants its capability:

```php
use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class GetRoutesTool extends Tool implements RequiresCapability
{
    public function capability(): Capability
    {
        return InspectionCapability::Routes->capability();
    }

    public function handle(Request $request, Beacon $beacon, Principal $caller): Response
    {
        return Response::json($beacon->inspect($caller, 'routes')->toArray());
    }
}

$this->app->make(\Beacon\Mcp\McpRegistry::class)->tool(GetRoutesTool::class);
```

Tools must stay thin: they call `Beacon::inspect()` with the injected `Principal` and return its redacted `Payload`. They never query Laravel internals directly or check permissions themselves. Packages that add a new kind of inspection register its read capability explicitly:

```php
app(\Beacon\Security\CapabilityRegistry::class)->register(Capability::read('horizon'), 'Horizon supervisor metadata.');
```

## MCP tools

Every Beacon tool is read-only, requires exactly one capability, and runs only for an authenticated caller whose capability is allowed (see [Authorization and the read-only model](#authorization-and-the-read-only-model)). Tools return their data both as `structuredContent`, validated by a published output schema, and as a JSON text copy for clients that do not support structured content.

| Tool                          | Capability    | Purpose                                                       |
| ----------------------------- | ------------- | ------------------------------------------------------------- |
| `beacon.get_application_info` | `application` | Laravel, PHP and Beacon versions, environment, debug and maintenance mode, URL, timezone and locales |

### `beacon.get_application_info`

Safe metadata about the Laravel application and the PHP runtime serving the MCP connection. AI agents are told to call it first when investigating an application.

|                 |                                                                           |
| --------------- | ------------------------------------------------------------------------- |
| Capability      | `application` (`application:read`)                                      |
| Inspector       | `application` (`Beacon\Inspectors\ApplicationInspector`)                 |
| Parameters      | None. Any arguments sent by the client are ignored.                       |
| Annotations     | `readOnlyHint: true`, `idempotentHint: true`, `openWorldHint: false`      |

Allow it explicitly:

```dotenv
BEACON_ALLOWED_CAPABILITIES=application
```

To remove the tool entirely, disable its inspector. The tool then no longer appears in `tools/list` and cannot be called:

```php
// config/beacon.php
'inspectors' => [
    'application' => ['enabled' => false],
],
```

#### Response

```json
{
    "schema_version": 1,
    "name": "Checkout",
    "environment": "production",
    "debug": false,
    "url": "https://shop.example.com",
    "timezone": "UTC",
    "locale": "en",
    "fallback_locale": "en",
    "maintenance_mode": false,
    "laravel_version": "13.35.0",
    "php_version": "8.3.6",
    "beacon_version": "v0.1.0"
}
```

Every field is always present, in this order. `null` means **unknown**: the value is missing, invalid or could not be read. It never means "off" and is never replaced by a default.

| Field              | Type             | Source                                    | `null` when                                                      |
| ------------------ | ---------------- | ----------------------------------------- | ---------------------------------------------------------------- |
| `schema_version`   | integer          | Beacon                                    | never                                                            |
| `name`             | string or null   | `app.name`, trimmed                       | missing, empty, longer than 255 characters, or contains control characters or invalid UTF-8 |
| `environment`      | string           | `app()->environment()`                    | never                                                            |
| `debug`            | boolean or null  | `app.debug`                               | not a real boolean (e.g. the string `"false"`, which Laravel would treat as enabled) |
| `url`              | string or null   | `app.url`, sanitized (see below)          | missing, or not an absolute `http`/`https` URL with a valid host |
| `timezone`         | string or null   | `app.timezone`, as configured             | missing or not a timezone PHP recognizes                          |
| `locale`           | string or null   | `app.locale`                              | missing or not a language tag such as `en`, `pt_BR` or `zh-Hant-TW` |
| `fallback_locale`  | string or null   | `app.fallback_locale`                     | same as `locale`                                                 |
| `maintenance_mode` | boolean or null  | Laravel's maintenance-mode driver         | the driver cannot be read (e.g. its cache store is unreachable)  |
| `laravel_version`  | string           | `app()->version()`                        | never                                                            |
| `php_version`      | string           | `PHP_VERSION` of the serving process      | never                                                            |
| `beacon_version`   | string or null   | Composer's installed-package metadata     | Beacon's version cannot be determined                            |

The schema is versioned by `schema_version`. Fields may be added within schema 1, but existing fields will not be removed or change meaning.

#### Security

- **URL sanitization.** `url` keeps only the scheme, host, port and path. User information (`user:password@`), query strings and fragments are always removed, since they commonly carry credentials, signatures or tokens. Internationalized host names must be configured in their ASCII (punycode) form.
- **Never returned:** application keys (`APP_KEY`, `app.previous_keys`), the cipher, database, cache, mail and service credentials, API tokens, session and cookie configuration, environment variables, `.env` contents, other configuration values, filesystem paths, server variables and request headers. The inspector reads only the values listed above. It never reads `.env`, `$_SERVER` or `getenv()`.
- **Defence in depth.** The response still passes through Beacon's payload redaction and `ResponseSanitizer`, so a secret placed in, for example, `APP_NAME` is replaced with `[REDACTED]`.
- **Same rules in every environment.** Beacon applies the same authentication, authorization and redaction whether the application reports `local` or `production`. `environment` and `debug` are reported, never trusted.
- **Version disclosure.** Exact Laravel and PHP versions help an investigator and an attacker alike. Only allow the `application` capability for clients you trust.

#### Errors

| Situation                                                          | Result                                                                    |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------- |
| Not authenticated (HTTP)                                           | `401` from the endpoint; the tool never runs                              |
| `application` capability not allowed                               | JSON-RPC error `-32001` "Permission denied."                              |
| Authorization denied at the tool itself (defence in depth)         | Tool result with `isError: true` and "Permission denied."                 |
| Inspector disabled                                                 | The tool is not listed; calling it returns "Tool not found"               |
| Inspection fails unexpectedly                                      | Tool result with `isError: true` and "Application information is currently unavailable." |

Failure messages are fixed strings. Exception messages, classes, stack traces and paths are never returned, even with `APP_DEBUG=true`, and Beacon does not log them.

#### Limitations

- **The runtime is the one serving MCP.** Over stdio, `php_version` is the PHP CLI's version, which can differ from the PHP-FPM version serving web traffic. Over HTTP it is the web server's PHP.
- **Configuration as loaded.** Values reflect the configuration of the process answering the call. With `php artisan config:cache`, changes appear only after the cache is rebuilt.
- **Maintenance mode** is read through Laravel's configured driver. With the `cache` driver this is a read from that cache store.
- **Audit events.** A successful call is authorized twice, by the MCP execution gate and again when the inspector runs, so two `AuthorizationEvaluated` events are dispatched per call.

## Architecture

Beacon keeps collecting information separate from exposing it:

```text
Inspector  ──►  Data (DTO)  ──►  Analyzer  ──►  Payload  ──►  MCP / CLI
 collects       typed,           deterministic    normalized
                serializable     assessment       + redacted
```

| Namespace           | Responsibility                                                                                                                                      |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Beacon\Contracts`  | `Inspector`, `Analyzer`, `HealthCheck`, `Data`, `Redactor`, `Authorizer`, `Authenticator`, `RequiresCapability`                                      |
| `Beacon\Core`       | `Beacon` (entry point), `InspectorRegistry`, `Payload` (output boundary), `BeaconConfig` (typed config), `Version` (installed version)                |
| `Beacon\Data`       | `DataObject` base DTO, `Normalizer`, DTOs (`ApplicationInfo`, `SafeValue`, `HealthCheckResult`)                                                      |
| `Beacon\Inspectors` | Built-in inspectors: `ApplicationInspector`                                                                                                          |
| `Beacon\Security`   | `Capability`, `InspectionCapability`, `CapabilityRegistry`, `CapabilityPolicy`, `CapabilityAuthorizer`, `KeyPatternRedactor`, `UrlSanitizer`, `BearerTokenAuthenticator`, `Principal` |
| `Beacon\Events`     | `AuthorizationEvaluated` (audit hook)                                                                                                               |
| `Beacon\Health`     | `HealthStatus`                                                                                                                                      |
| `Beacon\Mcp`        | `BeaconServer`, `ServerRegistration`, `McpRegistry`, `ExecutionGate`, `CallerResolver`, `ResponseSanitizer`, `Http\AuthenticateMcpRequest`, `Tools\GetApplicationInfoTool`. The only namespace that depends on `laravel/mcp`. |
| `Beacon\Diagnostics` | `Diagnostics` (the `beacon:check` checks), `DiagnosticReport`, `CheckResult`, `DiagnosticStatus`. Uses `laravel/mcp` only when it is installed. |
| `Beacon\Console`   | The thin Artisan commands `beacon`, `beacon:install` and `beacon:check`                                                                              |

Inspectors know nothing about MCP. Consumers call `Beacon::inspect()`, which authorizes the inspector's capability, runs it and returns a `Payload`. A `Payload` can only be built from `Data` and is always normalized and redacted, so a secret copied into a DTO by mistake is still removed before output.

### Writing an inspector

The built-in pattern, which every Beacon inspector follows, is:

1. An inspector (`Beacon\Inspectors\ApplicationInspector`) reads an explicit list of values through injected services and validates each one, using `null` for anything missing or invalid.
2. It returns a typed DTO (`Beacon\Data\ApplicationInfo`) that holds only scalars, so nothing else can be serialized.
3. A thin MCP tool (`Beacon\Mcp\Tools\GetApplicationInfoTool`) declares the capability, calls `Beacon::inspect()` with the caller's `Principal`, and returns the redacted payload as structured content.

Applications and packages can add their own inspectors the same way. Register a read capability for them, since only registered capabilities can be allowed:

```php
use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\DataObject;
use Beacon\Security\Capability;
use Beacon\Security\CapabilityRegistry;
use Beacon\Security\Principal;

final readonly class ReleaseInfo extends DataObject
{
    public function __construct(
        public ?string $release,
    ) {}
}

/** @implements Inspector<ReleaseInfo> */
final class ReleaseInspector implements Inspector
{
    public function capability(): Capability
    {
        return Capability::read('release');
    }

    public function inspect(array $parameters = []): Data
    {
        $release = config('app.release');

        return new ReleaseInfo(is_string($release) ? $release : null);
    }
}

app(CapabilityRegistry::class)->register(Capability::read('release'), 'The deployed release identifier.');
app(\Beacon\Core\InspectorRegistry::class)->register('release', ReleaseInspector::class);

// Requires BEACON_ALLOWED_CAPABILITIES to include "release".
app(\Beacon\Core\Beacon::class)->inspect(Principal::local(), 'release')->toArray();
// ['release' => '2026.10.13-1']
```

## Development

```bash
composer test         # PHPUnit
composer analyse      # PHPStan (Larastan, level 9)
composer format       # PHP-CS-Fixer
composer pre-check    # format check + analyse + test
```

## License

MIT
