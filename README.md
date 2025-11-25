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
```

Full steps: [Install Guide](./docs/Install.md)

## Assistants & Prompts
- Assistants are defined via `atlas-nexus.assistants` where each class extends `AssistantDefinition`. These classes control the assistant key, name/description, system prompt, model, temperature/top_p/max tokens, default max steps, availability flags, and the full configuration for tools + provider tools.
- Runtime tables reference assistants via an `assistant_key` string so definitions stay code-driven and stateless.

See: [PRD — Assistants](./docs/PRD/Assistants-and-Prompts.md)

## Prompt Variables
- Use placeholders like `{USER.NAME}` or `{USER.EMAIL}` inside assistant definition prompts; values are resolved right before the model request.
- Built-in thread placeholders include `{THREAD.ID}`, `{THREAD.TITLE}` (or `None` when the thread lacks a title), `{THREAD.SUMMARY}` (or `None` when absent), `{THREAD.LONG_SUMMARY}`, `{THREAD.RECENT.IDS}` (comma-separated up to 5 of the user’s most recent threads for the assistant excluding the active thread, or `None` when there are no others), and `{DATETIME}`.
- Defaults pull from the thread's authenticatable user when the `users` table exists.
- Add more via `atlas-nexus.variables` by implementing `PromptVariableGroup` (multiple keys in one class) with `PromptVariableContext` (thread, assistant, prompt, user).
- When invoking `PromptVariableService::apply`, you can merge inline overrides: `['ORG.NAME' => 'Atlas HQ']`.

## Threads & Messages
- Threads (`ai_threads`) hold `group_id`, `assistant_key`, `user_id`, status, metadata, and `prompt_snapshot` when prompt locking is enabled.
- Messages (`ai_messages`) store `assistant_key`, role, content type, sequence, status, tokens, and provider ids.
- `ThreadMessageService::sendUserMessage` records user + assistant placeholder and runs responses inline or queued.
- Existing threads reuse the prompt stored in `ai_threads.prompt_snapshot`; disable this guard via `atlas-nexus.threads.snapshot_prompts` if prompt updates should immediately apply mid-thread.

See: [PRD — Threads & Messages](./docs/PRD/Threads-and-Messages.md)

## Tools & Tool Runs
- Tools are code-defined (`NexusTool` implementations) and registered by key via the `ToolRegistry` service. Resolve the registry from the container to call `register(new ToolDefinition('custom', CustomTool::class))` when adding custom tools.
- Assistant tool keys determine availability; missing handlers are skipped.
- Tool runs (`ai_message_tools`) log Prism tool calls with statuses, inputs/outputs, `group_id`, `assistant_key`, and `tool_key`.
- The built-in `fetch_more_context` tool lets assistants search up to 10 additional threads (title, summary, keywords, memories, and message body) to gather relevant context mid-conversation.

See: [PRD — Tools & Tool Runs](./docs/PRD/Tools-and-ToolRuns.md)

## Memories
- Thread-level memories (`ai_threads.memories`) capture durable facts/preferences scoped to user + assistant.
- A background memory extractor assistant reviews unchecked messages based on the configurable threshold (`atlas-nexus.memory.pending_message_count`, default `4`) and appends durable facts to `ai_threads.memories`, which can be surfaced with `{MEMORY.CONTEXT}`.

See: [PRD — Memories](./docs/PRD/Memories.md)

## Inline vs Queued Responses
- `ThreadMessageService::sendUserMessage(..., $dispatchResponse = true)` dispatches `RunAssistantResponseJob` (optional queue `atlas-nexus.queue`).
- Set `$dispatchResponse=false` to run `AssistantResponseService` inline.
- Both paths mark assistant messages as failed on exceptions; tool runs and memory ids are captured in metadata.

## Purging Soft Deletes
- Soft-deleted threads, messages, tool runs, and memories remain in the database until purged.
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
See the [Contributing Guide](./.github/CONTRIBUTING.md) and [Agents](./AGENTS.md).

## License
MIT — see [LICENSE](./LICENSE).
