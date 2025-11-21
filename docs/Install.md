# Atlas Nexus Installation

This guide outlines the minimal steps to install Atlas Nexus and prepare its database schema and built-in seeds.

## Table of Contents
- [Install the Package](#install-the-package)
- [Publish Configuration](#publish-configuration)
- [Select a Database Connection](#select-a-database-connection)
- [Publish Migrations](#publish-migrations)
- [Run Migrations](#run-migrations)
- [Run Seeds](#run-seeds)
- [Usage Entry Points](#usage-entry-points)
- [Also See](#also-see)

## Install the Package
```bash
composer require atlas-php/nexus
```

## Publish Configuration
Generate `config/atlas-nexus.php` to customize table names, pipelines, tools, and future feature flags.

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

## Run Seeds
Seed built-in Nexus resources (e.g., the Memory tool) after migrations:

```bash
php artisan atlas:nexus:seed
```

Default seeders:
- `MemoryFeatureSeeder` adds the `memory` tool to assistants when `atlas-nexus.tools.memory.enabled=true`.
- `WebSearchAssistantSeeder` creates the built-in web summarizer assistant/prompt used by the `web_search` tool when `atlas-nexus.tools.web_search.enabled=true`.
- `ThreadManagerAssistantSeeder` creates the built-in thread manager assistant/prompt used for title/summary generation when `atlas-nexus.tools.thread_manager.enabled=true`.

You can add custom seeders by extending the `seeders` array in `config/atlas-nexus.php` or by calling the `NexusSeederService::extend()` method at runtime.

## Usage Entry Points
- `Atlas\Nexus\NexusManager` — access pipeline configuration and create Prism text requests.
- `Atlas\Nexus\Services\Threads\ThreadMessageService` — record user messages and generate assistant responses (inline or queued).
- `Atlas\Nexus\Services\Threads\ThreadStateService` — build state snapshots (messages, tools, memories) for LLM requests.

## Also See
- [PRD — Atlas Nexus](./PRD/Atlas-Nexus.md)
- [PRD — Assistants & Prompts](./PRD/Assistants-and-Prompts.md)
- [PRD — Threads & Messages](./PRD/Threads-and-Messages.md)
- [PRD — Tools & Tool Runs](./PRD/Tools-and-ToolRuns.md)
- [PRD — Memories](./PRD/Memories.md)
- [PRD — Example Usage](./PRD/Example-Usage.md)
