# Atlas Nexus Installation

This guide outlines the minimal steps to install Atlas Nexus and prepare its database schema.

## Table of Contents
- [Install the Package](#install-the-package)
- [Publish Configuration](#publish-configuration)
- [Select a Database Connection](#select-a-database-connection)
- [Publish Migrations](#publish-migrations)
- [Run Migrations](#run-migrations)
- [Purge Soft Deletes](#purge-soft-deletes)
- [Usage Entry Points](#usage-entry-points)
- [Also See](#also-see)

## Install the Package
```bash
composer require atlas-php/nexus
```

## Publish Configuration
Generate `config/atlas-nexus.php` to customize table names, queue selection, assistant definitions, and prompt variable providers.

```bash
php artisan vendor:publish --tag=atlas-nexus-config
```

## Select a Database Connection
(Optional) Pin Nexus tables to a dedicated connection:

```dotenv
ATLAS_NEXUS_DATABASE_CONNECTION=tenant
```

You can also set the connection directly in `config/atlas-nexus.php`.

## Publish Migrations
Nexus tables must be present before use.

```bash
php artisan vendor:publish --tag=atlas-nexus-migrations
```

## Run Migrations
```bash
php artisan migrate
```

## Purge Soft Deletes
Soft-deleted assistants, prompts, messages, and memories remain queryable via `withTrashed()` until they are purged. Schedule the purge command to permanently delete the rows:

```bash
php artisan atlas:nexus:purge --chunk=250
```

The chunk option is optional and defaults to 100.

## Usage Entry Points
- `Atlas\Nexus\NexusManager` — access Prism text requests.
- `Atlas\Nexus\Services\Threads\ThreadMessageService` — record user messages and generate assistant responses (inline or queued).
- `Atlas\Nexus\Services\Threads\ThreadStateService` — build state snapshots (messages, tools, memories) for LLM requests.

## Also See
- [PRD — Atlas Nexus](./PRD/Atlas-Nexus.md)
- [PRD — Assistants & Prompts](./PRD/Assistants-and-Prompts.md)
- [PRD — Threads & Messages](./PRD/Threads-and-Messages.md)
- [PRD — Tools & Tool Runs](./PRD/Tools-and-ToolRuns.md)
- [PRD — Memories](./PRD/Memories.md)
- [PRD — Example Usage](./PRD/Example-Usage.md)
