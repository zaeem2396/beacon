# Changelog

## Unreleased

### Added

- `beacon.get_database_status` and `php artisan beacon:database` report the default connection. MySQL, MariaDB, and SQLite are probed. PostgreSQL and SQL Server are identified and not opened. Credentials, hosts, and schema are not returned. Schema inspection is still later.

## v0.1.0 — 2026-10-25

Initial public release. This version is Laravel Application Intelligence: read-only application metadata for AI agents over MCP, plus a local CLI. It is early development. It is not a monitoring product and it does not diagnose incidents by itself.

Database intelligence, migration analysis, runtime error intelligence, queue intelligence, and deployment intelligence are not in this release. They remain later roadmap items.

The reported Beacon version comes from Composer's installed-package metadata (`Composer\InstalledVersions`). A `v0.1.0` install reports `v0.1.0`. A branch install reports that branch, such as `dev-main`. There is no separate hardcoded version constant.

### Added

- Installable Laravel package `zaeem2396/beacon` with automatic service-provider discovery.
- Published configuration at `config/beacon.php`.
- Typed inspection results, redacted before they leave the package.

### Security

- MCP is disabled until `BEACON_MCP_ENABLED`, a token of at least 32 characters, and an allowed environment are all set. The default environment list is `local`.
- The HTTP transport is a second opt-in. It requires `Authorization: Bearer` on every request and HTTPS unless that requirement is turned off for local development.
- stdio does not ask the client for the token. The token must still be configured or the server is not registered. Anyone who can run `php artisan` as the application user can start it.
- Capabilities are default-deny and read-only. Mutating capabilities cannot be enabled. `*` allows every registered read capability.
- Secret-bearing keys and recognisable secret values are redacted. Unexpected failures return fixed messages and do not include exception text.

### CLI

- `php artisan beacon` shows the installed version, whether Beacon and MCP are active, and the available commands.
- `php artisan beacon:install` publishes configuration and prints setup steps. It does not edit `.env`, generate a token, or enable MCP.
- `php artisan beacon:check` reports Beacon's own installation and security posture. It does not inspect the application database, cache, or queue.
- `php artisan beacon:application` prints application metadata. `--json` uses the MCP schema.
- `php artisan beacon:health` prints the health report. Exit 0 means the report was printed. The health status is in the output.

### MCP

Requires `laravel/mcp` ^1.0. Without it, the Artisan commands and inspectors still run and the MCP server is not registered.

- Server handle `beacon`: `php artisan mcp:start beacon`, from the application root.
- Optional HTTP endpoint `POST /beacon/mcp`.
- Tools: `beacon.get_application_info`, `beacon.get_environment`, `beacon.get_routes`, `beacon.get_config`, `beacon.get_health`.
- No MCP resources or prompts.

### Inspections

- Application information: name, environment, debug, sanitized URL, timezone, locales, maintenance mode, and Laravel, PHP, and Beacon versions.
- Environment inspection: allowlisted settings and deterministic findings, without secret values.
- Route inspection: registered routes, methods, middleware, literal filters, and deterministic findings. Handlers are not invoked.
- Configuration inspection: an allowlisted set of configuration metadata. Callers cannot request arbitrary keys. Credentials are omitted.
- Health inspection: application boot, plus optional read-only database, cache, and queue probes. Those probes are off by default. The queue check never reports a worker as healthy just because a driver is configured.
