# Laravel Beacon

Laravel Beacon exposes safe, structured production information about a Laravel application to AI agents (Claude, Cursor and other MCP clients) so they can investigate production problems using real application evidence.

> **Status:** early development (v0.1). The package foundation, the MCP server foundation, MCP authentication and the read-only security model are in place. Inspectors, MCP tools and Artisan commands are not implemented yet. See [ROADMAP.md](ROADMAP.md).

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

```bash
composer require zaeem2396/beacon
```

The service provider is registered automatically through package discovery. To customise the configuration:

```bash
php artisan vendor:publish --tag=beacon-config
```

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
- **No tools, resources or prompts.** The server completes the MCP handshake and answers `ping` and the list methods with empty lists. It exposes no application data.

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

These define permissions for upcoming inspectors. No inspector or tool using them ships yet.

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
| `Beacon\Core`       | `Beacon` (entry point), `InspectorRegistry`, `Payload` (output boundary), `BeaconConfig` (typed config)                                              |
| `Beacon\Data`       | `DataObject` base DTO, `Normalizer`, shared DTOs (`SafeValue`, `HealthCheckResult`)                                                                  |
| `Beacon\Security`   | `Capability`, `InspectionCapability`, `CapabilityRegistry`, `CapabilityPolicy`, `CapabilityAuthorizer`, `KeyPatternRedactor`, `BearerTokenAuthenticator`, `Principal` |
| `Beacon\Events`     | `AuthorizationEvaluated` (audit hook)                                                                                                               |
| `Beacon\Health`     | `HealthStatus`                                                                                                                                      |
| `Beacon\Mcp`        | `BeaconServer`, `ServerRegistration`, `McpRegistry`, `ExecutionGate`, `CallerResolver`, `ResponseSanitizer`, `Http\AuthenticateMcpRequest`. The only namespace that depends on `laravel/mcp`. |

Inspectors know nothing about MCP. Consumers call `Beacon::inspect()`, which authorizes the inspector's capability, runs it and returns a `Payload`. A `Payload` can only be built from `Data` and is always normalized and redacted, so a secret copied into a DTO by mistake is still removed before output.

### Writing an inspector

```php
use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\DataObject;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;

final readonly class ApplicationInfo extends DataObject
{
    public function __construct(
        public string $laravelVersion,
        public string $phpVersion,
    ) {}
}

/** @implements Inspector<ApplicationInfo> */
final class ApplicationInspector implements Inspector
{
    public function capability(): Capability
    {
        return InspectionCapability::Application->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        return new ApplicationInfo(app()->version(), PHP_VERSION);
    }
}

app(\Beacon\Core\InspectorRegistry::class)->register('application', ApplicationInspector::class);

// Requires BEACON_ALLOWED_CAPABILITIES to include "application".
app(\Beacon\Core\Beacon::class)->inspect(Principal::local(), 'application')->toArray();
// ['laravel_version' => '13.x', 'php_version' => '8.3.x']
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
