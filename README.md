# Laravel Beacon

Laravel Beacon exposes safe, structured production information about a Laravel application to AI agents (Claude, Cursor and other MCP clients) so they can investigate production problems using real application evidence.

> **Status:** early development (v0.1). The package foundation is in place; inspectors, MCP tools and Artisan commands are not implemented yet. See [ROADMAP.md](ROADMAP.md).

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
