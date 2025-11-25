# Agents

This guide defines the conventions and best practices for all contributors working on this **Laravel package repository**. These rules ensure consistency, clarity, and compatibility for all consumers installing this package via Composer.

> For validation and commit requirements, see **[CONTRIBUTING.md](./.github/CONTRIBUTING.md)**.

---

## Purpose

This repository provides **standalone Laravel packages** designed for installation in consumer Laravel applications. All logic must remain **framework-integrated but package-isolated**.

All **Agents** must treat any **Product Requirement Documents (PRDs)** included in the project as the **absolute source of truth** for functionality, naming, structure, and business logic.

> **PRDs override all assumptions or prior conventions.** When a PRD defines behavior, data flow, or naming, Agents must implement code that directly matches those definitions. If uncertainty arises, Agents must defer to the PRD or seek clarification before coding.

---

## Core Principles

1. Follow **PSR-12** and **Laravel Pint** formatting.
2. Use **strict types** and modern **PHP 8.2+** syntax.
3. All code must be **stateless**, **framework-aware**, and **application-agnostic**.
4. Keep everything **self-contained**: no hard dependencies on a consuming app.
5. Always reference **PRDs** for functional requirements and naming accuracy.
6. Write clear, testable, and deterministic code.
7. Every class must include a **PHPDoc block at the top of the file** summarizing its purpose and usage details.

*Example:*

```php
/**
 * Class UserWebhookService
 *
 * Handles webhook registration, processing, and retry logic for user-related events.
 */
```

---

## Structure

Each package must follow this layout. **No new top-level directories are allowed.**

```
package-name/
├── composer.json
├── docs/
│   ├── Install.md               # consumer friendly install instructions
│   └── PRD/                     # source of truth to requirements
├── src/
│   ├── Console/Commands/
│   ├── Enums/
│   ├── Providers/
│   │   └── PackageServiceProvider.php
│   ├── Models/
│   ├── Services/
│   │   ├── Models/              # per-model services, one model per service
│   │   └── <Domain>/            # feature services grouped by domain
│   ├── Integrations/            # third-party, APIs, external services
│   ├── Contracts/
│   ├── Exceptions/
│   └── Support/                 # small helpers, traits, utilities
├── config/
├── database/
│   ├── factories/
│   └── migrations/
├── tests/
│   ├── Unit/                    # tests without database, helpers/support
│   └── Feature/<Domain>/        # feature/business tests grouped by domain
├── AGENTS.md
└── README.md
```

---

## Layer Responsibilities

### Services/Models (Model Layer)

* One service per model.
* Extends `Atlas\Core\Service\ModelService`.
* Handles create, update, delete, and model-specific helpers.
* May normalize data pre-persistence when needed.
* Must not orchestrate workflows, perform cross-domain logic, or call integrations.
* Naming examples: `ContactModelService`, `TaskModelService`.

### Services/<Domain> (Business Layer)

* Groups business logic by domain.
* Implements PRD use cases and workflows.
* Orchestrates multiple model services.
* Manages transactions.
* Coordinates integrations, events, and jobs.
* Must use `Services/Models` for persistence.
* Services named by intent (e.g., `ScheduleCallWithContactService`).

### Integrations (External Layer)

* Low-level API/SDK clients.
* Handles authentication, requests, and responses.
* Contains no business logic.
* Examples: `OpenAiClient`, `StripeClient`.

### Support (Utility Layer)

* Small helpers, traits, value objects, simple transformers.
* Fully stateless.
* No business logic, workflows, database access, or external calls.
* Cannot depend on models, services, or integrations.

---

## Naming & Conventions

### Class Naming

* Providers: `*ServiceProvider`
* Services: `*Service`
* Contracts: `*Contract`
* Models: singular
* Exceptions: `*Exception`

### File & Namespace Structure

* Use the package namespace root.
* Group by domain when applicable.
* Use camelCase for variables and methods.

### Methods

* Short, descriptive, predictable.
* Boolean methods prefixed with `is`, `has`, or `can`.
* Must match PRD terminology.

---

## Service Provider Rules

* Register only what is required.
* Use package auto-discovery.
* Keep logic minimal.
* No business logic in providers.

---

## Code Practices

1. Business logic lives in `Services/`.
2. Use `Services/Models` for CRUD.
3. Use `Services/<Domain>` for workflows.
4. `Support/` contains helpers only.
5. Config files belong in `config/` with sensible defaults.
6. Write full test coverage.
7. Enforce strict type declarations.
8. Use custom exceptions for expected failures.
9. Minimize dependencies.
10. Ensure PRD alignment.
11. Include a PHPDoc block on every class.

---

## Task Checklist

1. Run Pint: `composer lint`.
2. Run tests: `composer test`.
3. Run larastan: `composer analyse`.
4. Confirm PRD alignment.
5. Remove debugging and unused imports.
6. Verify all classes include required PHPDoc.

---

## Enforcement

Agents must:

1. Follow this guide precisely.
2. Use PRDs as the single source of truth.
3. Complete the task checklist.
4. Request clarification when PRDs are incomplete.
5. Avoid any direct vendor edits.

Tasks violating these rules will be rejected.
