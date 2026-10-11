# Laravel Beacon

Laravel Beacon exposes safe, structured production information about a Laravel application to AI agents (Claude, Cursor and other MCP clients) so they can investigate production problems using real application evidence.

> **Status:** early development (v0.1). The package foundation and the MCP server foundation are in place. Inspectors, MCP tools, MCP authentication and Artisan commands are not implemented yet. See [ROADMAP.md](ROADMAP.md).

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
- **Mutating capabilities are always denied.** This cannot be changed through configuration.
- **Sensitive values are always redacted.** Keys such as `APP_KEY`, `DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, `Authorization` or `Cookie`, and values that look like secrets (private keys, credentials in URLs, bearer tokens, JWTs, cloud API keys), are replaced with `[REDACTED]`. You can add your own keys under `redaction.additional_keys`, but the built-in rules cannot be removed.

```dotenv
BEACON_ENABLED=true
BEACON_MCP_ENABLED=false
BEACON_MCP_TOKEN=
BEACON_MCP_ENVIRONMENTS=local
```

## MCP server

> [!WARNING]
> The MCP server does not authenticate clients yet. MCP authentication is the next implementation step. Until it is complete, do not expose the server publicly or enable it in production.

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

### Transport

Only the **stdio** transport is supported. Beacon registers a local server named `beacon`; no HTTP route is registered. Your MCP client starts the server as a subprocess:

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

### Current limitations

- **No client authentication.** `BEACON_MCP_TOKEN` must be configured to enable the server, but clients are not asked to present it yet.
- **No tools, resources or prompts.** The server completes the MCP handshake and answers `ping` and the list methods with empty lists. It exposes no application data.
- **No HTTP transport.** It will only be added once authentication protects it.

### Extending the server

Beacon modules expose MCP primitives by registering them explicitly. Nothing is exposed otherwise:

```php
use Beacon\Mcp\McpRegistry;

$this->app->make(McpRegistry::class)->tool(GetRoutesTool::class);
```

MCP tools must stay thin: they call `Beacon::inspect()` and return its redacted `Payload`, and never query Laravel internals directly.

## Architecture

Beacon keeps collecting information separate from exposing it:

```text
Inspector  ──►  Data (DTO)  ──►  Analyzer  ──►  Payload  ──►  MCP / CLI
 collects       typed,           deterministic    normalized
                serializable     assessment       + redacted
```

| Namespace           | Responsibility                                                                                                                                      |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Beacon\Contracts`  | `Inspector`, `Analyzer`, `HealthCheck`, `Data`, `Redactor`, `Authorizer`                                                                             |
| `Beacon\Core`       | `Beacon` (entry point), `InspectorRegistry`, `Payload` (output boundary), `BeaconConfig` (typed config)                                              |
| `Beacon\Data`       | `DataObject` base DTO, `Normalizer`, shared DTOs (`SafeValue`, `HealthCheckResult`)                                                                  |
| `Beacon\Security`   | `Capability`, `CapabilityPolicy`, `CapabilityAuthorizer`, `KeyPatternRedactor`                                                                       |
| `Beacon\Health`     | `HealthStatus`                                                                                                                                      |
| `Beacon\Mcp`        | `BeaconServer`, `ServerRegistration`, `McpRegistry`. The only namespace that depends on `laravel/mcp`.                                               |

Inspectors know nothing about MCP. Consumers call `Beacon::inspect()`, which authorizes the inspector's capability, runs it and returns a `Payload`. A `Payload` can only be built from `Data` and is always normalized and redacted, so a secret copied into a DTO by mistake is still removed before output.

### Writing an inspector

```php
use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\DataObject;
use Beacon\Security\Capability;

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
        return Capability::read('application');
    }

    public function inspect(array $parameters = []): Data
    {
        return new ApplicationInfo(app()->version(), PHP_VERSION);
    }
}

app(\Beacon\Core\InspectorRegistry::class)->register('application', ApplicationInspector::class);

app(\Beacon\Core\Beacon::class)->inspect('application')->toArray();
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
