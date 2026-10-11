# Laravel Beacon

## Product Vision

Laravel Beacon is an AI-native production intelligence platform for Laravel applications.

It gives AI agents a secure, structured and Laravel-aware interface to inspect application health, configuration, database state, queues, deployments, errors, performance and security. MCP is the primary interface between Laravel Beacon and AI clients such as Claude, Cursor and other MCP-compatible agents.

The long-term product is:

```text
Laravel Application
        │
        ▼
Laravel Beacon OSS
        │
        ├── CLI
        ├── Production checks
        ├── MCP Server
        └── Laravel intelligence
                │
                ▼
        AI Client / Agent
        │
        ▼
Beacon Cloud
        │
        ├── Teams
        ├── Deployments
        ├── Incidents
        ├── AI investigations
        ├── Alerts
        └── Enterprise controls
```

---

## Status Legend

* 🟢 **Done**
* 🟡 **In Progress**
* 🔴 **Pending**

At project kickoff, implementation modules are intentionally marked 🔴.

---

# Phase 0 — Foundation

## v0.1.0 — MCP Foundation

| Module                   | Prompt                                                                                                                                                                                                                            | Status |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Package foundation       | Create the Laravel package structure, configuration, service provider, console commands, contracts and testing foundation. The package must be installable independently and must not depend on any of Zaeem's existing packages. | 🟢     |
| MCP server               | Implement a Laravel-native MCP server exposing Beacon functionality to external AI clients. MCP must be treated as a core product interface rather than a secondary integration.                                                | 🔴     |
| MCP authentication       | Implement secure authentication for MCP connections with configurable tokens/credentials and environment-aware restrictions. Production access must be explicitly enabled and never accidentally exposed by default.              | 🔴     |
| Read-only security model | Establish read-only as the default capability model. No destructive or mutating operation should be possible in v0.1.                                                                                                             | 🔴     |
| CLI foundation           | Add the Beacon Artisan command structure, including a root `Beacon` command and commands for diagnostics and MCP management.                                                                                                  | 🔴     |

---

## v0.1.0 — Laravel Application Intelligence

| Module                   | Prompt                                                                                                                                                                                 | Status |
| ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Application information  | Expose Laravel version, PHP version, environment, application name, runtime information and relevant framework metadata through CLI and MCP. Sensitive secrets must never be returned. | 🔴     |
| Environment inspection   | Inspect important Laravel environment/configuration values and identify unsafe or suspicious production configuration without exposing secret values.                                  | 🔴     |
| Route inspection         | Allow AI clients to inspect registered routes, HTTP methods, middleware and route metadata. Provide structured data suitable for AI reasoning rather than raw framework dumps.         | 🔴     |
| Configuration inspection | Provide safe access to important Laravel configuration state while masking credentials, secrets and sensitive values.                                                                  | 🔴     |
| Health inspection        | Provide a normalized application health result covering Laravel boot, database, cache, queue and other supported infrastructure components.                                            | 🔴     |

---

# v0.2.0 — Database Intelligence

| Module                  | Prompt                                                                                                                                                                                               | Status |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Database status         | Expose database connection health, driver, version and connection metadata without exposing credentials. Support MySQL/MariaDB first.                                                                | 🔴     |
| Schema inspection       | Allow AI to inspect tables, columns, indexes, constraints and relationships in a structured format. Avoid loading unnecessarily large datasets.                                                      | 🔴     |
| Migration inspection    | Expose pending/applied migrations and migration history to the AI.                                                                                                                                   | 🔴     |
| Migration risk analysis | Analyze Laravel migrations for potentially dangerous operations such as destructive column changes, large table modifications, unsafe indexes and locking risks. Return a risk level with reasoning. | 🔴     |
| Schema comparison       | Allow the AI to compare current schema state against migration expectations and identify potentially dangerous differences.                                                                          | 🔴     |

---

# v0.3.0 — Runtime & Error Intelligence

| Module                   | Prompt                                                                                                                                                                                 | Status |
| ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Recent exceptions        | Expose recent application exceptions in a sanitized structured format. Include timestamps, exception type, message, endpoint/job context and useful metadata without exposing secrets. | 🔴     |
| Error aggregation        | Group similar exceptions so an AI agent can reason about frequency and patterns rather than receiving hundreds of duplicate errors.                                                    | 🔴     |
| Request intelligence     | Provide recent request information including endpoint, status, latency and relevant performance indicators where available.                                                            | 🔴     |
| Slow request detection   | Identify unusually slow requests and provide enough context for AI investigation.                                                                                                      | 🔴     |
| AI investigation context | Build a normalized investigation context that combines errors, requests, deployments, database activity and configuration changes.                                                     | 🔴     |

---

# v0.4.0 — Queue & Worker Intelligence

| Module                    | Prompt                                                                                                                  | Status |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------- | ------ |
| Queue health              | Inspect configured queue connections and determine whether queues appear healthy.                                       | 🔴     |
| Failed jobs               | Expose failed job counts, recent failures, queue names and safe job metadata. Sensitive payload data must be sanitized. | 🔴     |
| Queue latency             | Detect queue backlog and latency where the underlying queue driver provides enough information.                         | 🔴     |
| Worker inspection         | Expose worker/Horizon status where available without requiring Horizon as a dependency.                                 | 🔴     |
| Job failure investigation | Allow AI to correlate failed jobs with application exceptions, deployments and configuration changes.                   | 🔴     |

---

# v0.5.0 — Deployment Intelligence

| Module                       | Prompt                                                                                                                                                           | Status |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Deployment model             | Introduce a deployment abstraction capable of representing deployment ID, commit, timestamp, environment and application version.                                | 🔴     |
| Deployment tracking          | Allow deployments to be recorded through CLI/API/GitHub Actions integration.                                                                                     | 🔴     |
| Deployment comparison        | Compare two deployments and identify relevant changes to migrations, routes, configuration, dependencies and application metadata.                               | 🔴     |
| Post-deployment verification | Provide a command that verifies application health after deployment and returns a machine-readable result.                                                       | 🔴     |
| Deployment risk score        | Calculate a deterministic deployment risk score based on configured checks. AI may explain the score but must not be responsible for the underlying calculation. | 🔴     |

---

# v0.6.0 — AI Investigation Engine

| Module                   | Prompt                                                                                                                                                         | Status |
| ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Investigation model      | Create a structured investigation model representing a production problem, evidence collected, hypotheses, confidence and recommended actions.                 | 🔴     |
| Cross-signal correlation | Allow the AI to correlate errors, requests, queues, database state, migrations, deployments and configuration rather than analyzing each signal independently. | 🔴     |
| Root-cause workflow      | Provide an investigation workflow that progressively gathers evidence before producing a diagnosis. The AI must distinguish evidence from assumptions.         | 🔴     |
| Confidence               | Require AI-generated conclusions to include confidence and supporting evidence. Never present speculative conclusions as facts.                                | 🔴     |
| Investigation reports    | Produce machine-readable and human-readable investigation reports suitable for developers, engineering managers and incident records.                          | 🔴     |

---

# v0.7.0 — MCP Advanced Capabilities

| Module           | Prompt                                                                                                                                           | Status |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------ |
| MCP resources    | Expose useful Laravel production context as MCP resources where appropriate instead of forcing everything through tools.                         | 🔴     |
| MCP prompts      | Add reusable investigation prompts such as deployment investigation, queue investigation, migration safety review and application health review. | 🔴     |
| Tool discovery   | Improve MCP tool descriptions and schemas so AI agents understand when and how each Beacon capability should be used.                          | 🔴     |
| Tool permissions | Introduce granular capability permissions so organizations can control which tools an AI client can access.                                      | 🔴     |
| Audit trail      | Record MCP tool usage including client, user, tool, timestamp and result metadata while avoiding sensitive data storage.                         | 🔴     |

---

# v0.8.0 — CI/CD & Developer Workflow

| Module                           | Prompt                                                                                                   | Status |
| -------------------------------- | -------------------------------------------------------------------------------------------------------- | ------ |
| GitHub Actions                   | Provide an official GitHub Action for Beacon checks.                                                   | 🔴     |
| CI deployment gate               | Allow deterministic production checks to fail a deployment when configured risk thresholds are exceeded. | 🔴     |
| PR analysis                      | Provide optional migration/configuration/deployment risk analysis during pull requests.                  | 🔴     |
| Deployment verification workflow | Provide a standard workflow for deploy → verify → report.                                                | 🔴     |
| Machine-readable output          | Add JSON and other structured outputs for CI/CD systems.                                                 | 🔴     |

---

# v0.9.0 — Controlled Actions

This is the first version where Beacon can potentially move beyond observation.

| Module            | Prompt                                                                                                  | Status |
| ----------------- | ------------------------------------------------------------------------------------------------------- | ------ |
| Action framework  | Create a permissioned action framework that separates read-only investigation from mutating operations. | 🔴     |
| Retry failed job  | Allow an authorized user/agent to request retrying selected failed jobs after explicit approval.        | 🔴     |
| Cache operations  | Support controlled cache operations with explicit permission and audit logging.                         | 🔴     |
| Worker operations | Support controlled worker restart operations where the deployment environment supports it.              | 🔴     |
| Human approval    | Every destructive or operational action must support explicit human approval before execution.          | 🔴     |

---

# v1.0.0 — Production-Ready OSS

| Module         | Prompt                                                                                            | Status |
| -------------- | ------------------------------------------------------------------------------------------------- | ------ |
| Stable API     | Stabilize public contracts, configuration and MCP interfaces.                                     | 🔴     |
| Security audit | Review authentication, authorization, secret handling, data exposure and MCP attack surfaces.     | 🔴     |
| Documentation  | Create installation, configuration, MCP client, production deployment and security documentation. | 🔴     |
| Compatibility  | Define and test supported Laravel/PHP versions.                                                   | 🔴     |
| Performance    | Ensure Beacon has minimal impact when installed in production applications.                     | 🔴     |
| Release        | Release stable v1.0 with semantic versioning and upgrade documentation.                           | 🔴     |

---

# Commercial Phase

## v1.1 — Beacon Cloud Foundation

The OSS package remains the runtime intelligence layer. Cloud becomes the centralized control plane for organizations running multiple Laravel applications.

| Module                   | Prompt                                                                                               | Status |
| ------------------------ | ---------------------------------------------------------------------------------------------------- | ------ |
| Application registration | Allow organizations to register Laravel applications and associate Beacon installations with them. | 🔴     |
| Deployment history       | Store deployments and deployment health results centrally.                                           | 🔴     |
| Application inventory    | Provide an organization-level inventory of Laravel applications and environments.                    | 🔴     |
| Historical health        | Store health and risk history so teams can identify trends.                                          | 🔴     |
| AI investigations        | Allow investigations to be persisted and shared across teams.                                        | 🔴     |

---

## v1.2 — Team Product

| Module              | Prompt                                                                                        | Status |
| ------------------- | --------------------------------------------------------------------------------------------- | ------ |
| Organizations       | Support organizations and teams.                                                              | 🔴     |
| RBAC                | Introduce organization, admin, developer and read-only roles.                                 | 🔴     |
| Alerts              | Add email, Slack and webhook notifications for important production events.                   | 🔴     |
| Incident management | Turn Beacon investigations into incidents with status, timeline and resolution information. | 🔴     |
| Audit logs          | Provide organization-level audit logs.                                                        | 🔴     |

---

## v1.3 — Enterprise

| Module             | Prompt                                                                 | Status |
| ------------------ | ---------------------------------------------------------------------- | ------ |
| SSO                | Add enterprise authentication integrations.                            | 🔴     |
| Advanced policies  | Allow organizations to define production safety policies.              | 🔴     |
| Custom checks      | Allow organizations to create organization-specific production checks. | 🔴     |
| Extended retention | Offer configurable long-term historical data retention.                | 🔴     |
| Enterprise support | Establish paid support and implementation packages.                    | 🔴     |

---

# Commercial Strategy

The business should not depend entirely on subscription revenue.

The first revenue target should be **paid implementation/pilot contracts**.

## Stage 1 — OSS adoption

Use the OSS package to establish credibility and demonstrate the technology.

Primary objective:

```text
Developers install Beacon
        ↓
Companies discover Beacon
```

Do not optimize for GitHub stars alone.

---

## Stage 2 — Paid pilot

Target companies running multiple Laravel applications.

Offer:

> Beacon Production Intelligence Pilot

Example scope:

* install Beacon
* connect production applications
* configure MCP
* connect their preferred AI client
* configure deployment tracking
* configure production checks
* create custom checks
* train their engineering team
* provide investigation/reporting workflow

Initial pilot pricing should be negotiated based on company size and scope, with a target range around **$2,000–$5,000** for an initial implementation.

---

## Stage 3 — Implementation contracts

For companies with significant Laravel infrastructure:

```text
Implementation
+
Custom integrations
+
Security configuration
+
Deployment integration
+
Custom checks
+
AI/MCP integration
```

Target contracts can grow toward **$5,000–$20,000+** depending on complexity.

---

## Stage 4 — Recurring revenue

Convert successful pilots into:

```text
Beacon Cloud subscription
+
Enterprise support
+
Implementation
+
Custom integrations
```

Potential customers:

* SaaS companies
* ecommerce companies
* agencies managing many Laravel applications
* fintech companies
* marketplaces
* enterprises with legacy Laravel systems
* companies with large queues/workers
* companies with multiple production environments

---

# Core Product Principle

Beacon must never become:

> "An LLM wrapper around Laravel."

The product's value comes from:

```text
Laravel-native intelligence
+
MCP
+
structured production evidence
+
cross-signal correlation
+
AI investigation
+
enterprise operational controls
```

The AI should be the investigator.

Beacon should be the source of truth.
