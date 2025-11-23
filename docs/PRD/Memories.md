# PRD — Memories

Defines shared memory storage and access rules across assistants and threads.

## Table of Contents
- [Memory Model](#memory-model)
- [Scopes & Ownership](#scopes--ownership)
- [Creation Rules](#creation-rules)
- [Retrieval Rules](#retrieval-rules)
- [Deletion Rules](#deletion-rules)
- [Services & Tools](#services--tools)

## Memory Model
Table: `ai_memories`

| Field                 | Description                                                     |
|-----------------------|-----------------------------------------------------------------|
| `id`                  | Primary key                                                     |
| `group_id`            | Optional tenant/account grouping (inherits from thread)         |
| `owner_type`          | Enum (`user`,`assistant`,`org`)                                 |
| `owner_id`            | Owner identifier                                                |
| `assistant_key`       | Nullable assistant scope                                        |
| `thread_id`           | Nullable provenance thread id                                   |
| `source_message_id`   | Nullable message provenance                                     |
| `source_tool_run_id`  | Nullable tool run provenance                                    |
| `kind`                | Memory type (fact, preference, summary, task, etc.)             |
| `content`             | Natural language content                                        |
| `metadata`            | JSON metadata                                                   |
| `created_at/updated_at` | Timestamps                                                    |

## Scopes & Ownership
- `owner_type` + `owner_id` determine who can access the memory:
  - `user` — tied to thread user
  - `assistant` — tied to current assistant
  - `org` — shared across org/tenant (`owner_id` provided by consumer)
- `assistant_key` nullable: null = global; otherwise limited to the assistant key.

## Creation Rules
- `AiMemoryService::saveForThread`:
  - Resolves `owner_id` based on `owner_type` (thread user for `user`, assistant id for `assistant`, provided id for `org`).
  - Sets `group_id` from the thread.
  - Allows optional `metadata`, `source_message_id`, `source_tool_run_id`.
  - `thread_id` set only when `thread_specific` flag is true.

## Retrieval Rules
- `AiMemoryService::listForThread` returns memories where:
  - Owner matches thread user (`user`), assistant (`assistant`), or org (`org`).
  - `assistant_key` is null or matches the assistant key.
  - Optional `memory_ids` filter limits to explicit identifiers.
  - Ordered by `id`.
- `ThreadStateService` pulls memories for inclusion in LLM context and injects ids into assistant message metadata.

## Deletion Rules
- `AiMemoryService::removeForThread` validates assistant/user context before deletion.
- Cascading deletes:
  - Deleting a thread removes associated memories (`AiThreadService` cascade).

## Services & Tools
- `AiMemoryService` — CRUD + scoped retrieval and deletion.
- `MemoryTool` — Built-in tool to save, fetch, and delete memories; attach it to assistants by including `memory` in their `tools()` configuration.
- `ThreadStateService` — Optionally injects Memory tool into available tool list when active.
