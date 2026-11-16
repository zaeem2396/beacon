# Changelog

## Unreleased

### Added

- `beacon.get_runtime_info` and `php artisan beacon:runtime` report PHP and Laravel runtime facts plus allowlisted drivers. Capability: `runtime`.
- `beacon.get_recent_exceptions` and `php artisan beacon:exceptions` read recent exceptions from supported file log channels. Capability: `exceptions`.
- `beacon.get_error_aggregates`, `beacon.get_error_summary`, and `php artisan beacon:errors` group exceptions for the inspected window only. Capabilities: `error_aggregate`, `error_summary`.
- `beacon.get_log_summary` and `php artisan beacon:logs` summarize supported file log channels. Capability: `logs`.
- `beacon.get_queue_status` and `php artisan beacon:queues` report queue configuration and safely observable backend state. Capability: `queue`.
- `beacon.get_failed_jobs` and `php artisan beacon:failed-jobs` report failed jobs without payloads. Capability: `failed_jobs`.
- `beacon.get_scheduler_status` and `php artisan beacon:scheduler` list scheduled task definitions without executing them. Capability: `scheduler`.
- `beacon.get_recent_requests` and `php artisan beacon:requests` read opt-in HTTP request observations from Beacon storage. Capability: `requests`.
- `beacon.get_request_summary` and `php artisan beacon:request-summary` summarize the inspected request window. Capability: `request_summary`.
- `beacon.get_slow_requests` and `php artisan beacon:slow-requests` classify recorded requests that exceeded configured duration thresholds. Capability: `slow_requests`. Equality with the threshold is not slow.
- `beacon.get_slow_request_summary` and `php artisan beacon:slow-request-summary` summarize endpoint latency for the inspected window. Capability: `slow_request_summary`. p95 needs at least 5 samples. p99 needs at least 20 samples.
- `php artisan beacon:requests-prune` deletes old Beacon request rows using `beacon.requests.retention_hours`. Maintenance only; does not require the `requests` capability.
- Publishable `beacon-migrations` tag for the dedicated `beacon_requests` table. Collection stays off until `BEACON_REQUESTS_COLLECTION=true`.

### Security

- Runtime, exception, log, queue, failed-job, scheduler, request, and slow-request capabilities are default-deny and independent.
- Log and exception reads are confined to configured file channels under `storage/logs`.
- Failed-job inspection never selects or deserializes payloads and never retries jobs.
- Scheduler inspection never runs scheduled tasks.
- Request collection never stores bodies, cookies, Authorization headers, or query strings. Middleware registration alone does not collect.
- Slow request tools never invent causes. AI investigation context remains pending.

## v0.2.0 — 2026-11-14

Database Intelligence. Beacon can report the default connection, inspect MySQL and MariaDB schema metadata, compare migration files with the repository, classify static migration risk, and compare normalized schema snapshots. Findings are heuristic. Beacon does not execute migration PHP or SQL, read table rows, modify schemas, or prove that a deployment will fail.

Runtime error intelligence, deeper queue worker inspection, and deployment intelligence remain later roadmap items.

The reported Beacon version still comes from Composer's installed-package metadata. A `v0.2.0` install reports `v0.2.0`. A branch install reports that branch, such as `dev-main`.

### Added

- `beacon.get_database_status` and `php artisan beacon:database` report the default connection. MySQL, MariaDB, and SQLite are probed. PostgreSQL and SQL Server are identified and not opened. Credentials, hosts, and schema are not returned. Capability: `database`.
- `beacon.get_database_schema` and `php artisan beacon:schema` report MySQL and MariaDB schema metadata. The `schema` capability is separate from `database`. Rows, credentials, and caller SQL are not accepted.
- `beacon.get_migrations` and `php artisan beacon:migrations` compare migration filenames with the repository. The `migrations` capability is separate from `database` and `schema`. Migration PHP is not executed, and a missing history table is not created.
- `beacon.analyze_migration_risks` and `php artisan beacon:migration-risks` report static risk findings for migration source. The `migration_risk` capability is separate from `migrations`, `database`, and `schema`. Findings are heuristic. Migration PHP and SQL are not executed. Depends on `nikic/php-parser`.
- `beacon.create_schema_snapshot`, `beacon.compare_schema_snapshots`, `php artisan beacon:schema-snapshot`, and `php artisan beacon:schema-compare` compare normalized schema snapshots. `schema_snapshot` and `schema_compare` are separate default-deny capabilities. Live capture also requires `schema`. Rows are not read, and snapshot JSON is not executed.

### Security

- Database, schema, migrations, migration risk, schema snapshot, and schema compare remain default-deny and independent of each other.
- Snapshot capture reuses schema inspection. Comparison of saved snapshots does not open the database. Snapshot files are confined to `storage/beacon/snapshots`.
- Risk analysis never evaluates migration PHP. Schema comparison never unserializes snapshots or runs SQL from snapshot text.

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
