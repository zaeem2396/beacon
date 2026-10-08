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

# 8. v0.1 MCP Tools

Implement the first tools as read-only capabilities.

## `Beacon.get_application_info`

Return:

* Laravel version
* PHP version
* environment
* application name
* runtime information
* framework information

Do not expose secrets.

---

## `Beacon.get_environment`

Return safe environment/configuration metadata.

The output must clearly identify:

```text
present
missing
unsafe
sensitive
```

Sensitive values must be redacted.

---

## `Beacon.get_routes`

Return:

* route URI
* HTTP methods
* route name
* controller/action
* middleware

Support pagination/limits if necessary.

Do not dump unlimited routes.

---

## `Beacon.get_config`

Expose only approved configuration namespaces.

Never expose the entire configuration repository blindly.

Initial namespaces may include:

```text
app
cache
database
queue
session
filesystems
mail
logging
```

Sensitive values must be redacted.

---

## `Beacon.get_database_status`

Return:

* configured driver
* connection status
* database version where available
* latency
* connection name

Never expose credentials.

---

## `Beacon.get_database_schema`

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

## `Beacon.get_pending_migrations`

Return:

* migration name
* migration status
* batch
* timestamp where available

---

## `Beacon.get_recent_exceptions`

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

## `Beacon.get_failed_jobs`

Return:

* queue
* failed job identifier
* job class
* timestamp
* exception information
* retry information where available

Do not expose arbitrary job payloads.

---

## `Beacon.get_queue_status`

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
Beacon.get_application_info
Beacon.get_environment
Beacon.get_routes
Beacon.get_config
Beacon.get_database_status
Beacon.get_database_schema
Beacon.get_pending_migrations
Beacon.get_recent_exceptions
Beacon.get_failed_jobs
Beacon.get_queue_status
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
php artisan Beacon
php artisan Beacon:install
php artisan Beacon:check
php artisan Beacon:mcp
```

The exact command names can be refined during implementation.

`Beacon:check` should produce both:

human-readable output:

```text
Laravel Beacon

Application       ✓
Configuration     ⚠
Database          ✓
Queue             ✓
Migrations        ⚠

Risk: MEDIUM
```

and machine-readable output:

```bash
php artisan Beacon:check --json
```

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
runtime-obeserva
```

in the first release.

Later create optional integrations:

```text
Beacon-schema-lens
Beacon-runtime-insight
Beacon-runtime-shield
Beacon-obeserva
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
