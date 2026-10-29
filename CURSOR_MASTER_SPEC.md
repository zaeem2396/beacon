# Laravel Beacon — Cursor Master Specification

## 1. Mission

Build Laravel Beacon as a Laravel-native production intelligence package that exposes safe, structured production information to AI agents through MCP.

The primary use case is:

> An engineer connects an AI client such as Claude, Cursor or another MCP-compatible client to a Laravel application and asks the AI to investigate production problems using real application evidence.

Examples:

```text
Why did the application become slower after the last deployment?

Why are queues backing up?

Is this migration safe to deploy?

Why did checkout start returning 500 errors?

What changed between the last healthy deployment and the current deployment?

Is this production application configured safely?
```

The AI must be able to answer these questions by inspecting the actual Laravel application through Beacon MCP capabilities.

---

# 2. Product Boundaries

Beacon is NOT:

* another Laravel admin panel
* another generic monitoring SaaS
* another Laravel Telescope clone
* another generic AI chatbot
* an LLM wrapper
* a generic MCP framework
* a generic deployment platform

Laravel already provides MCP infrastructure.

Beacon provides the **Laravel production intelligence exposed through MCP**.

---

# 3. Architecture

Initial architecture:

```text
Laravel Application
       │
       ▼
Laravel Beacon Package
       │
       ├── Core
       │
       ├── Inspectors
       │
       ├── Security
       │
       ├── Health
       │
       ├── Analysis
       │
       └── MCP Adapter
               │
               ▼
          MCP Client
               │
        ┌──────┼──────┐
        ▼      ▼      ▼
     Cursor  Claude  Other AI
```

Do not introduce a cloud service into v0.1.

Do not require an external database for v0.1.

Do not require an LLM API key for v0.1.

---

# 4. Core Architectural Principle

Beacon must separate:

```text
Data collection
      ↓
Normalization
      ↓
Risk analysis
      ↓
MCP exposure
      ↓
AI reasoning
```

Do not put business logic directly inside MCP tool implementations.

Bad:

```php
class GetDatabaseStatusTool
{
    public function execute()
    {
        // database queries
        // security
        // formatting
        // risk analysis
        // MCP response
    }
}
```

Preferred:

```text
DatabaseInspector
      ↓
DatabaseStatus
      ↓
DatabaseRiskAnalyzer
      ↓
MCP Resource/Tool
```

This allows CLI, tests, MCP and future Cloud functionality to reuse the same core logic.

---

# 5. Package Structure

Use a maintainable package structure similar to:

```text
src/
├── Console/
│   └── Commands/
│
├── Contracts/
│   ├── Inspector.php
│   ├── Analyzer.php
│   ├── HealthCheck.php
│   └── Capability.php
│
├── Inspectors/
│   ├── ApplicationInspector.php
│   ├── EnvironmentInspector.php
│   ├── RouteInspector.php
│   ├── ConfigInspector.php
│   ├── DatabaseInspector.php
│   ├── MigrationInspector.php
│   ├── QueueInspector.php
│   └── ErrorInspector.php
│
├── Analysis/
│   ├── Risk/
│   ├── Deployment/
│   └── Investigation/
│
├── Security/
│   ├── Redactor.php
│   ├── Authorizer.php
│   └── CapabilityPolicy.php
│
├── MCP/
│   ├── Tools/
│   ├── Resources/
│   ├── Prompts/
│   └── Server/
│
├── Health/
│
├── Data/
│   └── DTOs/
│
└── BeaconServiceProvider.php
```

Names can be adjusted when implementation reveals better boundaries, but the separation of concerns must remain.

---

# 6. Data Model

Do not expose arbitrary Laravel objects directly to MCP.

Create explicit DTO/value objects.

Example:

```php
final readonly class ApplicationInfo
{
    public function __construct(
        public string $laravelVersion,
        public string $phpVersion,
        public string $environment,
        public string $applicationName,
    ) {}
}
```

Responses must be:

* deterministic
* serializable
* documented
* safe
* bounded in size

---

# 7. Security Model

Security is a first-class feature.

Default behavior:

```text
MCP disabled
      ↓
Explicitly enabled
      ↓
Authentication required
      ↓
Read-only capabilities
```

Production must never accidentally expose an unauthenticated MCP endpoint.

Sensitive values must be redacted.

Examples:

```text
APP_KEY
DB_PASSWORD
AWS_SECRET_ACCESS_KEY
MAIL_PASSWORD
API tokens
OAuth secrets
private keys
session secrets
```

must never be returned.

If a configuration value is sensitive, return metadata such as:

```json
{
    "key": "DB_PASSWORD",
    "present": true,
    "sensitive": true,
    "value": "[REDACTED]"
}
```

Never:

```json
{
    "key": "DB_PASSWORD",
    "value": "actual-password"
}
```

---

# 8. MCP Tools

Application information, environment inspection, route inspection, configuration inspection, health inspection, and database status are implemented. The other tools in this section are the later read-only plan (schema, migrations, exceptions, and queues). Do not implement them until their roadmap version.

## `beacon.get_application_info`

Implemented. Also available locally as `php artisan beacon:application` (`--json` uses the same schema). Requires the `application` capability.

Return:

* schema version
* application name
* environment
* debug mode
* sanitized application URL (scheme, host, port, and path only)
* timezone
* locale and fallback locale
* maintenance-mode status
* Laravel version
* PHP version of the process serving the call
* Beacon version

Missing, invalid, or unreadable optional values are `null`. Do not expose secrets, other configuration, environment variables, or filesystem paths.

---

## `beacon.get_environment`

Implemented. Requires the `environment` capability. There is no Artisan command for this inspection.

Return safe environment metadata and deterministic findings. Do not return the application identity report from `beacon.get_application_info`.

Metadata:

* environment name
* debug mode, when it is a real boolean
* timezone and locale, when valid
* whether configuration and routes are cached in the serving process
* an allowlist of settings, each `present`, `missing`, `invalid` or `sensitive`

Findings use `pass`, `fail` (confirmed misconfiguration), `risk` (potential problem with evidence) or `unknown` (the check could not be evaluated). `pass` and `unknown` have severity `none`. Production-only checks run only when the environment is exactly `production`.

Sensitive settings, including the application key, report presence only. The application URL is reduced to its scheme. Do not return `.env` contents, secrets, or arbitrary configuration keys.

---

## `beacon.get_routes`

Implemented. Requires the `routes` capability. There is no Artisan command for this inspection.

Return one page of route records and findings for the whole route table. A finding can mention a route that is not on the current page.

Each record, where the value is safe to disclose:

* HTTP methods, in a fixed order
* URI pattern
* route name
* domain, when one is configured
* normalized middleware names
* controller/action identifier, or `closure`
* `framework_generated`, which is `null` because route metadata does not reliably identify framework-generated routes

Missing or withheld values are `null`. Do not return closures, middleware objects, controllers, request objects, filesystem paths or source.

Filters are literal: HTTP method, URI substring or exact URI, route name substring or exact name, and exact middleware name. A single leading slash on a URI filter is ignored. `contains` is the default string match and is not a regular expression. `page` defaults to 1 (maximum 10000). `limit` defaults to 50 and cannot exceed 100. Invalid filters are rejected with a fixed message that does not repeat the input.

Findings:

* `duplicate_route_names` reports a repeated disclosed name. It is not a vulnerability.
* `sensitive_route_paths` is a heuristic whole-segment match. It does not mean the route is vulnerable, reachable or unauthenticated.
* `authentication_middleware_excluded` reports only a recognised authentication middleware name that is explicitly excluded. An unfamiliar alias is not treated as missing authentication.

Do not dispatch routes or resolve controller middleware. Do not dump an unlimited route list.

---

## `beacon.get_config`

Implemented. Requires the `config` capability. There is no Artisan command for this inspection.

Return one page of approved configuration settings and findings for the full allowlist. A finding can describe a setting that is not on the current page or in the selected category.

Each setting has a category, an allowlisted id, a state (`present`, `missing`, `invalid`, or `sensitive`), and a value. A present value is a boolean or a short classification. Every other state has `null`. Do not return `[REDACTED]` as a stand-in for a secret.

Categories are `application`, `database`, `cache`, `queue`, `session`, `mail`, `filesystem`, and `logging`. The Laravel file name `filesystems` is not a category. Callers cannot request nested paths, wildcards, or arbitrary keys. `beacon.inspectors.config.namespaces` is not a dump switch.

Do not return database passwords, connection strings, hosts, database names, usernames, sockets, application keys, tokens, mail credentials, private keys, OAuth secrets, session secrets, webhook URLs, filesystem paths, buckets, regions, or the contents of `.env`. Named connections are followed only when the name is a dot-free identifier. If the sensitivity of a field is uncertain, omit the value.

Filters are an approved category and an approved setting id. `page` defaults to 1 (maximum 10000). `limit` defaults to 50 and cannot exceed 100. Invalid filters are rejected with a fixed message that does not repeat the input.

Findings:

* `app_cipher` fails when the cipher is missing or not a recognised value.
* `app_maintenance_driver` passes when unset. It fails when the value is not `file` or `cache`.
* `database_connection`, `cache_store`, `queue_connection`, `mail_mailer`, `filesystem_disk`, and `logging_channel` fail when the default name is unset, unregistered, or uses an unrecognised driver. An unreadable name is `unknown`. An explicit unrecognised log level also fails. A missing log level does not.
* `session_driver` fails when the driver is missing or not recognised.
* `session_secure_cookie` fails only when the environment is exactly `production`, the application URL scheme is `https`, and the Secure flag is false. A missing flag in that context is `risk`. Otherwise the check is `pass` or `unknown`. It does not replace the broader environment-inspection cookie checks.

Do not connect to a database, cache, queue, or mail server, and do not mutate configuration. Adding a field requires a reviewed change to the code allowlist, not a configuration change. Reachability belongs to `beacon.get_health`, and only when that probe is enabled.

---

## `beacon.get_health`

Implemented. Also available locally as `php artisan beacon:health` (`--json` uses the same schema). Requires the `health` capability. Takes no arguments. A caller cannot enable a probe, choose checks, or change a timeout.

Return one report: `schema_version`, `status`, `summary`, `assessed_at`, `counts`, and `checks`. Each check has a stable name, description, status, summary, duration in milliseconds, remediation, and evidence. Evidence is a classified observation, never a copied configuration value, exception message, or connection string.

Statuses:

* `healthy`: the check completed and passed.
* `warning`: the check completed and the application or dependency is functioning with a meaningful limitation. This is the degraded status.
* `critical`: the check completed and found a confirmed failure. This is the unhealthy status.
* `unknown`: the check could not reliably determine health.
* `skipped`: the check was intentionally not executed or has no safe probe.

Aggregation, in order: any `critical` makes the report `critical`; otherwise any `warning` makes it `warning`; otherwise any `unknown`, or a report with no checks, makes it `unknown`; otherwise any `healthy` makes it `healthy`. Skipped checks are not passes and do not downgrade a healthy report. They stay visible in `counts`. A report whose checks were all skipped is `skipped`. An empty report is `unknown`.

Checks, in order:

* `application` always runs. It confirms this process has a booted Laravel application and readable version metadata. Maintenance mode is `warning`. It does not repeat environment or configuration findings.
* `database` runs `select 1` on the default connection only when `BEACON_HEALTH_DATABASE` is true. The timeout is `BEACON_HEALTH_DATABASE_TIMEOUT`, an integer from 1 to 5 seconds, default 2. MySQL, MariaDB, PostgreSQL, and SQL Server receive that timeout as a PDO attribute. A missing sqlite file is `unknown` and is not created. An existing sqlite file is opened read-only when PHP provides that flag. `:memory:` is probed in memory. An invalid timeout does not run the probe. A connection failure is `critical` and does not include the driver error or credentials.
* `cache` reads one fixed key and discards the value only when `BEACON_HEALTH_CACHE` is true and the driver is `apc`, `array`, `database`, `file`, `memcached`, `redis`, or `storage`. The null driver and drivers without a safe read, including `dynamodb`, `failover`, `octane`, and `session`, are `skipped`. The check does not put, increment, forget, or flush.
* `queue` classifies the default driver only when `BEACON_HEALTH_QUEUE` is true. It never dispatches or consumes a job, so a configured driver is `unknown`, not `healthy`.

Probes are off by default and run only when a health report is requested. They do not run on a normal HTTP request. One failed check does not drop the others. Do not migrate, write, send mail, enqueue, clear cache, or make a network call except the enabled database or cache probe. Configuration warnings stay on `beacon.get_config` and `beacon.get_environment`.

`beacon:health` exits `0` when the report was printed, including when the status is `critical`. The status is in the output. Exit `1` is denied. Exit `2` means the report could not be collected.

---

## `beacon.get_database_status`

Implemented. Also available locally as `php artisan beacon:database` (`--json` uses the same schema). Requires the `database` capability. Takes no arguments. A caller cannot choose a connection, a timeout, or SQL.

Return:

* schema version
* connection name (the Laravel connection key, not a host or database name)
* configured driver, when it is one of `mariadb`, `mysql`, `pgsql`, `sqlite`, `sqlsrv`
* status: `connected`, `unavailable`, `unknown`, or `unsupported`
* short server version where a probe completed and the version text was readable (`8.0.36`, `10.11.6-MariaDB`), otherwise null
* latency in milliseconds when the probe completed, otherwise null
* a fixed summary

MySQL, MariaDB, and SQLite are probed with `select 1` and a version statement. PostgreSQL and SQL Server are `unsupported` and are not opened. A missing sqlite file is `unknown` and is not created. The timeout is `BEACON_DATABASE_TIMEOUT`, an integer from 1 to 5 seconds, default 2. An invalid timeout does not connect. This is separate from the optional health database probe.

Exit `0` for `beacon:database` means the report was printed. The status is in the output. Exit `1` is denied. Exit `2` means the report could not be collected.

Never expose credentials, hosts, ports, database names, connection strings, or driver errors.

---

## `beacon.get_database_schema`

Return structured schema information.

Initial support:

* MySQL
* MariaDB

Structure:

```text
database
tables
columns
indexes
foreign keys
constraints
```

Do not return table data.

---

## `beacon.get_pending_migrations`

Return:

* migration name
* migration status
* batch
* timestamp where available

---

## `beacon.get_recent_exceptions`

Return sanitized recent application errors.

Each error should contain useful context such as:

```text
id
timestamp
exception class
message
route
HTTP status
request context
job context
frequency
```

Do not expose:

* credentials
* authorization headers
* cookies
* secrets
* arbitrary request bodies

---

## `beacon.get_failed_jobs`

Return:

* queue
* failed job identifier
* job class
* timestamp
* exception information
* retry information where available

Do not expose arbitrary job payloads.

---

## `beacon.get_queue_status`

Return:

* queue driver
* queues
* backlog where available
* worker status where available
* Horizon status where applicable

Horizon must be optional.

Beacon must not require Horizon.

---

# 9. Tool Naming

Use stable, explicit names.

Preferred:

```text
beacon.get_application_info
beacon.get_environment
beacon.get_routes
beacon.get_config
beacon.get_health
beacon.get_database_status
beacon.get_database_schema
beacon.get_pending_migrations
beacon.get_recent_exceptions
beacon.get_failed_jobs
beacon.get_queue_status
```

Avoid vague names such as:

```text
inspect
analyze
debug
get_data
```

The AI must understand what a tool does from its name and description.

---

# 10. Tool Descriptions

Every MCP tool must have an excellent description.

The description should explain:

1. what the tool provides
2. when an AI agent should use it
3. what it does not provide
4. security limitations
5. important parameters

Example:

```text
Get recent sanitized Laravel application exceptions.

Use this tool when investigating:
- HTTP 500 errors
- application failures
- error spikes
- regressions after deployments

This tool returns metadata and sanitized exception information.
Sensitive credentials, cookies, authorization headers and secrets
are never returned.

Use deployment and request tools alongside this tool when attempting
to determine root cause.
```

The goal is to make the MCP server **AI-friendly by design**.

---

# 11. AI Investigation Principle

Do not build an AI API in v0.1.

The AI lives in the MCP client.

Beacon provides evidence.

For example:

```text
User:
Why are checkout requests failing?

AI:
1. get_recent_exceptions
2. get_routes
3. get_database_status
4. get_failed_jobs
5. inspect recent deployment
6. compare evidence
7. formulate diagnosis
```

Beacon should not pretend to be an autonomous AI agent.

---

# 12. Investigation Evidence

Every future investigation should distinguish:

```text
Evidence
Hypothesis
Conclusion
Recommendation
Confidence
```

Example:

```json
{
    "conclusion": "Checkout failures are likely caused by the latest database migration.",
    "confidence": 0.91,
    "evidence": [
        "Deployment 184 introduced migration X",
        "Errors started 3 minutes after deployment",
        "Database errors reference missing index Y"
    ],
    "recommendation": "Review migration X before rollback."
}
```

The AI must never convert a hypothesis into a fact without evidence.

---

# 13. Migration Risk Analysis

This is one of the most important differentiators.

Detect potentially risky Laravel migrations such as:

```text
DROP TABLE
DROP COLUMN
CHANGE COLUMN
MODIFY COLUMN
large index creation
NOT NULL changes
type changes
large table rewrites
potential locking operations
```

Return:

```text
risk: low
risk: medium
risk: high
```

with deterministic reasoning.

Example:

```json
{
    "risk": "high",
    "reason": "Changing a populated column to NOT NULL may require a table-wide update.",
    "operation": "modify_column",
    "table": "orders",
    "column": "customer_id"
}
```

AI can explain this result, but the base risk analysis should be deterministic.

---

# 14. No Destructive Actions in v0.x Early Releases

Do not implement:

```text
rollback
restart workers
retry jobs
clear production cache
run migrations
delete data
```

until the permission/action architecture is fully designed.

Read-only first.

This is essential for gaining trust with enterprise customers.

---

# 15. CLI

Initial commands:

```bash
php artisan beacon
php artisan beacon:application
php artisan beacon:health
php artisan beacon:install
php artisan beacon:check
```

`beacon:application` prints the same metadata as `beacon.get_application_info`. `beacon:health` prints the same report as `beacon.get_health`, in human-readable form and as `--json`. Exit `0` means the report was printed. The health status is a field in that report, including when a check is critical.

`beacon:check` reports Beacon's own configuration and security posture. It is not the application health report, and `beacon:check --json` keeps the diagnostics schema.

---

# 16. Testing Requirements

Every inspector must have unit tests.

Every MCP tool must have MCP-level tests.

Test:

* normal operation
* missing configuration
* invalid configuration
* database unavailable
* queue unavailable
* sensitive data redaction
* pagination
* malformed input
* authorization failures
* production restrictions

Security tests are mandatory.

Especially test that:

```text
DB_PASSWORD
APP_KEY
AWS_SECRET_ACCESS_KEY
Authorization
Cookie
session data
```

cannot accidentally appear in MCP output.

---

# 17. Performance Requirements

Beacon must have minimal production overhead.

Do not:

* query everything on every request
* load entire tables
* inspect arbitrary application data
* execute expensive schema analysis during normal HTTP requests
* perform AI calls from application requests

Beacon is an inspection layer, not an always-running profiler in v0.1.

---

# 18. Dependency Policy

Keep dependencies minimal.

Do not introduce:

* OpenAI SDK
* Anthropic SDK
* proprietary AI SDK

into the core package unless there is a compelling reason later.

AI clients communicate through MCP.

The package should remain provider-neutral.

---

# 19. Existing Zaeem Packages

Do not require:

```text
schema-lens
runtime-insight
runtime-shield
runtime-observa
```

in the first release.

Later create optional integrations:

```text
Beacon-schema-lens
Beacon-runtime-insight
Beacon-runtime-shield
beacon-observa
```

or equivalent adapters.

Beacon must remain useful without them.

---

# 20. Version Implementation Rules

Cursor must implement **one version at a time**.

Do not jump ahead.

For each version:

```text
1. Read roadmap
2. Identify modules for current version
3. Implement module
4. Add tests
5. Run static analysis
6. Run test suite
7. Update documentation
8. Mark module complete
9. Only then move to next module
```

Never implement future functionality merely because it seems useful.

---

# 21. Definition of Done

A module is not complete when the code compiles.

A module is complete when:

```text
Implementation
+
Tests
+
Security coverage
+
Documentation
+
MCP schema/description
+
CLI integration where applicable
+
Static analysis
```

are complete.

---

# 22. Commercial Architecture Constraint

Do not build the OSS package in a way that prevents a future hosted product.

The core package should eventually be capable of sending selected structured events to Beacon Cloud.

However:

**Do not implement Cloud functionality in v0.1.**

The boundaries should simply remain clean enough that Cloud can later consume:

```text
Application health
Deployment events
Risk scores
Investigation reports
Audit events
```

---

# 23. Future Cloud Architecture

Eventually:

```text
Laravel Application
       │
       ▼
Beacon OSS
       │
       ├── Local MCP
       │
       └── Optional Cloud Connector
                    │
                    ▼
             Beacon Cloud
                    │
             ┌──────┼──────┐
             ▼      ▼      ▼
          Teams   History  AI
             │      │      │
             └──────┼──────┘
                    ▼
              Enterprise
```

Cloud should not be required for basic Beacon functionality.

---

# 24. Commercial Features Must Not Poison OSS

Do not arbitrarily cripple the OSS package.

The OSS package must provide genuine value.

Commercial functionality should primarily be:

* centralized management
* history
* teams
* collaboration
* alerts
* enterprise security
* organization-wide policy
* hosted infrastructure
* support
* custom integrations

The OSS product is the adoption engine.

---

# 25. First Demo

The first public demo should be extremely simple.

Run a deliberately broken Laravel application.

Connect Claude/Cursor using MCP.

Ask:

```text
Investigate why this Laravel application is unhealthy.
```

The AI should:

```text
inspect application
        ↓
inspect configuration
        ↓
inspect database
        ↓
inspect migrations
        ↓
inspect queue
        ↓
inspect exceptions
        ↓
correlate evidence
        ↓
produce diagnosis
```

The final answer should look approximately like:

```text
Production Investigation

Problem:
Checkout requests are returning HTTP 500.

Evidence:
1. Errors started 4 minutes after deployment #184.
2. Deployment #184 introduced migration X.
3. The checkout service queries column Y.
4. Database errors reference column/index Y.
5. No equivalent errors existed before deployment #184.

Likely cause:
Migration X caused the checkout query to fail.

Confidence:
91%

Recommendation:
Review migration X and compare it against deployment #183.
```

This is the core product demonstration.

---

# 26. The Product Test

At every stage ask:

> Does this make an AI agent substantially better at understanding and operating a Laravel production application?

If the answer is no, it probably does not belong in Beacon.

---

# 27. First Commercial Test

Before building Beacon Cloud, attempt to sell:

## Laravel Production Intelligence Pilot

Offer companies:

```text
Production installation
+
MCP integration
+
AI client integration
+
Deployment checks
+
Custom production checks
+
Investigation workflow
```

The goal is to discover what companies actually need before building a large SaaS platform.

The first paying customer is more valuable than the first 1,000 GitHub stars.

---

# 28. Long-Term Vision

Beacon should eventually become:

> **The production intelligence layer for Laravel applications.**

A developer should be able to connect their AI agent to a Laravel application and ask:

```text
What's wrong?
```

The AI should be able to investigate.

Then:

```text
What changed?
```

The AI should be able to compare deployments.

Then:

```text
Is this safe?
```

The AI should be able to analyze migrations/configuration/deployment risk.

Eventually:

```text
Fix it.
```

Beacon should be able to propose and, with explicit authorization, execute a controlled action.

The final product is:

```text
Observe
   ↓
Understand
   ↓
Investigate
   ↓
Recommend
   ↓
Approve
   ↓
Act
   ↓
Verify
```

This is the long-term product direction.

Do not attempt to implement the entire loop in v0.1.
