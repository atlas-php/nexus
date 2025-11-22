# PRD — Atlas Nexus

Atlas Nexus centralizes AI assistants, prompts, threads, messages, tools, tool runs, and shared memories. This document is the authoritative specification for overall responsibilities and shared schemas across the package.

## Table of Contents
- [System Overview](#system-overview)
- [Core Data Tables](#core-data-tables)
- [Job vs Inline Execution](#job-vs-inline-execution)
- [Seeded Built-ins](#seeded-built-ins)
- [Purge & Retention](#purge--retention)
- [Multi-Tenancy Support](#multi-tenancy-support)
- [Failure Semantics](#failure-semantics)
- [Also See](#also-see)

## System Overview
Nexus orchestrates:
- **Assistants & Prompts** — define personas and versioned system prompts.
- **Threads & Messages** — capture conversations and LLM responses.
- **Tools & Tool Runs** — register callable tools and log executions.
- **Memories** — persist reusable context across conversations.

## Core Data Tables
All tables support soft deletes unless noted otherwise. Default names are configurable via `config/atlas-nexus.php`.

| Table             | Purpose                                                     |
|-------------------|-------------------------------------------------------------|
| `ai_assistants`   | Assistant definitions, defaults, and active prompt linkage  |
| `ai_prompts`      | Versioned system prompts per assistant                      |
| `ai_threads`      | Conversation containers (user/tool threads)                 |
| `ai_messages`     | User and assistant messages in a thread                     |
| `ai_tool_runs`    | Execution logs for tool calls                               |
| `ai_memories`     | Reusable memory items scoped to users/assistants/orgs       |

Each table definition with fields is detailed in the linked PRDs below.

## Configuration
- `atlas-nexus.tools.registry` — maps custom tool keys to handler classes.
- `atlas-nexus.tools.options` — per-tool overrides (e.g., web search content limits, summary models).
- `atlas-nexus.provider_tools` — provider-level tool definitions passed directly to Prism (e.g., `web_search` domain filters, `code_interpreter` container config); `file_search` is only registered when `vector_store_ids` contains at least one id.
- `atlas-nexus.responses.queue` — optional queue name for assistant response jobs.
- `atlas-nexus.seeders` — list of seeders executed by `atlas:nexus:seed`.

## Job vs Inline Execution
- `ThreadMessageService::sendUserMessage` stages a pending assistant message and either dispatches `RunAssistantResponseJob` or runs inline based on the `$dispatchResponse` flag.
- `RunAssistantResponseJob` delegates to `AssistantResponseService`, which handles Prism calls, tool logging, and failure capture.
- Failures must mark the assistant message as `FAILED` with a `failed_reason`.

## Seeded Built-ins
- `atlas:nexus:seed` runs all configured seeders.
- Default seeders:
  - `WebSearchAssistantSeeder` — provisions the built-in web summarizer assistant/prompt used by the `web_search` tool when enabled.
  - `ThreadManagerAssistantSeeder` — provisions the built-in thread manager assistant/prompt for title/summary generation when enabled.
  - `DefaultAssistantSeeder` — provisions a general-purpose assistant/prompt so consumers have a ready default.
- Seeders are idempotent and safe to run repeatedly. Consumers can extend via config or `NexusSeederService::extend()`.
- `ThreadManagerTool` is available as a built-in tool when enabled via config.
- Seeders are idempotent and safe to run repeatedly. Consumers can extend via config or `NexusSeederService::extend()`.

## Purge & Retention
- Trashed assistants, prompts, messages, and memories retain their data until explicitly purged.
- `Atlas\Nexus\Services\NexusPurgeService::purge()` permanently deletes trashed rows in chunked batches so cascading deletes (e.g., tool runs tied to messages) reuse the existing model services.
- `php artisan atlas:nexus:purge --chunk=500` exposes the purge flow via CLI for scheduled maintenance.

## Multi-Tenancy Support
All conversation artifacts carry an optional `group_id` to align with tenant/account scoping:
- `ai_threads.group_id`
- `ai_messages.group_id`
- `ai_memories.group_id`
- `ai_tool_runs.group_id`

Services propagate `group_id` from threads to messages, memories, and tool runs automatically when present.

## Failure Semantics
- Assistant response failures (inline or job) must mark the assistant message as `FAILED` with the error message.
- Tool runs record statuses via `AiToolRunStatus` (`QUEUED`, `RUNNING`, `SUCCEEDED`, `FAILED`).

## Also See
- [Assistants & Prompts](./Assistants-and-Prompts.md)
- [Threads & Messages](./Threads-and-Messages.md)
- [Tools & Tool Runs](./Tools-and-ToolRuns.md)
- [Memories](./Memories.md)
- [Example Usage](./Example-Usage.md)
