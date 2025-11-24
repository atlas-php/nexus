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
7. Every class must include a **PHPDoc block at the top of the file** summarizing its purpose and expected usage details. These doc blocks are mandatory and intended to help both internal and external consumers understand the class role without reading its internals.

_Example:_

```php
/**
 * Class UserWebhookService
 *
 * Handles webhook registration, processing, and retry logic for user-related events.
 */
```

---

## Structure

Each package must follow this layout:

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
│   └── Support/
├── config/
├── database/
│   ├── factories/
│   └── migrations/
├── tests/
│   ├── Unit/                    # tests without database, helpers/support
│   └── Features/<Domain>/       # feature/business tests grouped by domain
├── AGENTS.md
└── README.md
```

### Service & Integration Rules

Keep `src/Services` organized follow these rules:

#### `src/Services/Models`

* Each class in `Services/Models` is responsible for **one Eloquent model** only.
* Responsibilities:
    * Extend `Atlas\Core\Service\ModelService` from the Atlas Core package.
    * Centralized `create`, `update`, `delete`, and common query helpers for that model.
    * Only normalize and parse data before it is persisted when needed.
* Must **not**:
    * Orchestrate multi-model workflows.
    * Contain cross-domain business rules.
    * Call external APIs directly.
* Naming convention examples:
    * `ContactModelService` in `Services/Models/ContactModelService.php`.
    * `TaskModelService` in `Services/Models/TaskModelService.php`.

These services act as the **model-facing layer** and are the preferred entrypoint when only a single model is affected.

#### `src/Services/<Domain>` (business / feature services)

* Each domain directory (e.g. `Services/Contacts`, `Services/Tasks`) groups **business-oriented services** by feature or domain.
* Responsibilities:
    * Express **use cases** defined in PRDs (e.g. `ScheduleCallWithContactService`, `CreateFollowUpTasksForLeadService`).
    * Orchestrate multiple model services and domain concepts.
    * Manage transactions when a workflow touches multiple models.
    * Coordinate with integrations (via `Integrations/` clients) and dispatch jobs/events.
* Must:
    * Be named by intent and follow the `*Service` suffix (e.g. `ScheduleCallWithContactService`).
    * Use `Services/Models/*` for persistence logic rather than talking to models directly.

These services form the **business layer** and should be the primary surface area exposed to consuming applications for higher-level workflows.

#### `src/Integrations`

* Houses low-level, reusable clients for **external systems** (e.g. HTTP APIs, third-party services).
* Responsibilities:
    * Encapsulate API calls, authentication, and request/response handling.
    * Remain free of package-specific business rules.
    * Wrappers for SDKs, third-party libraries, or other integrations.
* Example:
    * `Integrations/OpenAI/OpenAiClient.php`
    * `Integrations/Stripe/StripeClient.php`

Business services in `Services/<Domain>` may depend on these **integrations**.

---

## Naming & Conventions

### Class Naming

All classes should use PascalCase.

* **Service Providers:** `ServiceProvider` suffix.
* **Services:** `Service` suffix.
* **Contracts:** Interfaces use `Contract` suffix.
* **Models:** Singular names.
* **Exceptions:** use `Exception` suffix.

### File & Namespace Structure

* All PHP classes must use the package namespace root.
* Group by domain when applicable (`Services/Users/UserInviteService.php`).

### Variables & Methods

* Use `camelCase` for variables and methods.
* Prefix booleans with `is`, `has`, or `can`.
* Keep methods short, descriptive, and predictable.
* Ensure method and service names match **PRD-defined terminology** when applicable.

---

## Service Provider Rules

* Must handle **registration**, **publishing**, and **booting** cleanly.
* Register bindings, configs, routes, and migrations **only if required**.
* Use **package auto-discovery**.
* Keep provider logic minimal and do not include business logic.

---

## Code Practices

1. **Business Logic** — belongs in `Services/`, not controllers or providers. Use `Services/Models/*` for model-focused CRUD and `Services/<Domain>/*` for higher-level workflows that span multiple models or integrations.
2. **Configuration** — define publishable config files in `config/`, use sensible defaults.
3. **Testing** — use PHPUnit; cover both success and failure paths.
4. **Type Safety** — declare all parameter and return types.
5. **Error Handling** — use custom exceptions for expected failures.
6. **Dependencies** — keep minimal; prefer Laravel contracts over concrete bindings.
7. **PRD Alignment** — always verify that logic, method names, and service behavior align with the PRD before implementation.
8. **Documentation via Doc Blocks** — every class, interface/contract, and trait must include a top-level PHPDoc block explaining its purpose. This ensures consumers understand intent and maintainability is preserved.

---

## Task Checklist

Task is not completed unless the follow checks have been made: 

1. Run Pint: `composer lint` (formatted).
2. Run tests: `composer test` and passed without errors.
3. Run larastan: `composer analyse` and passed without errors.
4. Confirm PRD alignment for naming and functionality. 
5. Ensure no temporary debugging or unused imports remain. 
6. Verify that every class includes a valid PHPDoc with purpose and usage context.

---

## Enforcement

Any contribution that violates these standards or PRD requirements will be rejected or revised before merge.

_Every Agent is required to:_

1. Follow this guide precisely. 
2. Use PRDs as the **single source of truth** for all logic, naming, and intent. 
3. Run through the task checklist before completion. 
4. Seek clarification when a PRD is ambiguous or missing required details.

> **Failure to follow this guide will result in rejection of the task.**
