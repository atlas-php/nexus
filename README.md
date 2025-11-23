# Atlas Nexus

[![Build](https://github.com/atlas-php/nexus/actions/workflows/tests.yml/badge.svg)](https://github.com/atlas-php/nexus/actions/workflows/tests.yml)
[![coverage](https://codecov.io/github/atlas-php/nexus/branch/main/graph/badge.svg)](https://codecov.io/github/atlas-php/nexus)
[![License](https://img.shields.io/github/license/atlas-php/nexus.svg)](LICENSE)

**Atlas Nexus** is a Laravel package that centralizes AI assistants, prompts, chat threads, shared memories, and tool execution via Prism. It provides a consistent way to manage LLM conversations, attach tools, and persist contextual state across threads or tenants.

## Table of Contents
- [Overview](#overview)
- [Installation](#installation)
- [Assistants & Prompts](#assistants--prompts)
- [Prompt Variables](#prompt-variables)
- [Threads & Messages](#threads--messages)
- [Tools & Tool Runs](#tools--tool-runs)
- [Memories](#memories)
- [Inline vs Queued Responses](#inline-vs-queued-responses)
- [Seeding Built-ins](#seeding-built-ins)
- [Purging Soft Deletes](#purging-soft-deletes)
- [Sandbox](#sandbox)
- [Also See](#also-see)
- [Contributing](#contributing)
- [License](#license)

## Overview
Nexus orchestrates LLM workflows in four parts:
- **Assistants & Prompts:** define personas, defaults, and versioned system prompts.
- **Threads & Messages:** capture user/assistant exchanges with sequencing, status, and tokens.
- **Tools & Tool Runs:** register callable tools, attach them to assistants, and log executions.
- **Memories:** store reusable context per user/assistant/org for richer responses.

## Installation
```bash
composer require atlas-php/nexus
php artisan vendor:publish --tag=atlas-nexus-config
php artisan vendor:publish --tag=atlas-nexus-migrations
php artisan migrate
php artisan atlas:nexus:seed
```

Full steps: [Install Guide](./docs/Install.md)

## Assistants & Prompts
- Assistants live in `ai_assistants`; assistant-specific prompt versions live in `ai_assistant_prompts` (lineage scoped to each assistant via `original_prompt_id`).
- Each prompt row belongs to a single assistant; calling `AiAssistantPromptService::edit()` always creates a new version rather than mutating the row.
- Set `current_prompt_id` to pick the active prompt; threads can override via `assistant_prompt_id`.
- Allowed tool keys live in the assistant `tools` JSON column (e.g., `["memory","calendar_lookup"]`).

See: [PRD — Assistants & Prompts](./docs/PRD/Assistants-and-Prompts.md)

## Prompt Variables
- Use placeholders like `{USER.NAME}` or `{USER.EMAIL}` inside `ai_assistant_prompts.system_prompt`; values are resolved right before the model request.
- Built-in thread placeholders include `{THREAD.ID}`, `{THREAD.TITLE}`, `{THREAD.SUMMARY}`, `{THREAD.LONG_SUMMARY}`, `{THREAD.RECENT.IDS}` (comma-separated up to 5 of the user’s most recent threads for the assistant excluding the active thread, or `None` when there are no others), and `{DATETIME}`.
- Defaults pull from the thread's authenticatable user when the `users` table exists.
- Add more via `atlas-nexus.prompts.variables` by implementing `PromptVariableGroup` (multiple keys in one class) with `PromptVariableContext` (thread, assistant, prompt, user).
- When invoking `PromptVariableService::apply`, you can merge inline overrides: `['ORG.NAME' => 'Atlas HQ']`.

## Threads & Messages
- Threads (`ai_threads`) hold `group_id`, `assistant_id`, `user_id`, status, and prompt overrides.
- Messages (`ai_messages`) store role, content type, sequence, status, tokens, and provider ids.
- `ThreadMessageService::sendUserMessage` records user + assistant placeholder and runs responses inline or queued.

See: [PRD — Threads & Messages](./docs/PRD/Threads-and-Messages.md)

## Tools & Tool Runs
- Tools are code-defined (`NexusTool` implementations) and registered by key via `ToolRegistry`.
- Assistant tool keys and feature flags determine availability; missing handlers are skipped.
- Tool runs (`ai_tool_runs`) log Prism tool calls with statuses, inputs/outputs, `group_id`, and `tool_key`.

See: [PRD — Tools & Tool Runs](./docs/PRD/Tools-and-ToolRuns.md)

## Memories
- Memories (`ai_memories`) capture facts/preferences/summaries scoped to user, assistant, or org.
- Built-in `MemoryTool` lets assistants add/update/fetch/delete memories; thread state injects relevant memories into requests.

See: [PRD — Memories](./docs/PRD/Memories.md)

## Inline vs Queued Responses
- `ThreadMessageService::sendUserMessage(..., $dispatchResponse = true)` dispatches `RunAssistantResponseJob` (optional queue `atlas-nexus.responses.queue`).
- Set `$dispatchResponse=false` to run `AssistantResponseService` inline.
- Both paths mark assistant messages as failed on exceptions; tool runs and memory ids are captured in metadata.

## Seeding Built-ins
- Run `php artisan atlas:nexus:seed` after migrations.
- Default seeders:
  - `WebSearchAssistantSeeder` (creates the built-in web summarizer assistant/prompt used by the `web_search` tool).
  - `ThreadManagerAssistantSeeder` (creates the built-in thread manager assistant/prompt for title/summary generation).
- Built-in tools: `memory` (persist/query memories), `web_search` (fetch and optionally summarize website content), `thread_search` (search existing threads by title, summaries, keywords, or content), `thread_fetcher` (fetch single or multiple thread transcripts), and `thread_updater` (update or auto-generate thread title/summary metadata).
- Extend via `config/atlas-nexus.php` `seeders` array or `NexusSeederService::extend()` at runtime.

## Purging Soft Deletes
- Soft-deleted assistants, prompts, messages, and memories remain in the database until purged.
- Run `php artisan atlas:nexus:purge` to permanently delete trashed rows; ideal for scheduled retention workflows.
- Pass `--chunk=500` (or any positive number) to tune how many rows are processed per chunk. The command delegates to `NexusPurgeService` so cascading deletes (like tool runs tied to messages) stay consistent.

## Sandbox
A Laravel sandbox lives in [`sandbox/`](./sandbox) to try Nexus + Prism flows.
```bash
cd sandbox
composer install
php artisan migrate
```
Use `sandbox/.env` to set DB + Prism provider keys and adjust `PRISM_MAX_STEPS` as needed.

## Also See
- [PRD — Atlas Nexus](./docs/PRD/Atlas-Nexus.md)
- [Example Usage](./docs/PRD/Example-Usage.md)

## Contributing
See the [Contributing Guide](./.github/CONTRIBUTING.md).

## License
MIT — see [LICENSE](./LICENSE).
