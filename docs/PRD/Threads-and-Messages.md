# PRD — Threads & Messages

Defines how Nexus stores and processes conversations, including assistant response workflows.

## Table of Contents
- [Threads](#threads)
- [Messages](#messages)
- [Lifecycle Rules](#lifecycle-rules)
- [Assistant Response Flow](#assistant-response-flow)
- [Services](#services)

## Threads
Table: `ai_threads`

| Field                 | Description                                                       |
|-----------------------|-------------------------------------------------------------------|
| `id`                  | Primary key                                                       |
| `group_id`            | Optional tenant/account grouping                                  |
| `assistant_key`       | Assistant owner key                                               |
| `user_id`             | User owner                                                        |
| `type`                | `user` or `tool`                                                  |
| `parent_thread_id`    | Nullable reference to parent thread (no FK)                      |
| `parent_tool_run_id`  | Nullable reference to tool run (no FK)                           |
| `title`               | Optional title                                                    |
| `status`              | Enum (`open`, `archived`, `closed`)                               |
| `summary`             | Optional rolling summary                                          |
| `last_message_at`     | Nullable timestamp                                                |
| `last_summary_message_id` | Nullable FK-less pointer to the last summarized message      |
| `memories`            | JSON text storing durable thread-specific memories                |
| `metadata`            | JSON metadata                                                     |
| `created_at/updated_at/deleted_at` | Timestamps + soft deletes                            |

## Messages
Table: `ai_messages`

| Field                  | Description                                                       |
|------------------------|-------------------------------------------------------------------|
| `id`                   | Primary key                                                       |
| `group_id`             | Optional tenant/account grouping (inherits from thread)           |
| `thread_id`            | Thread id                                                         |
| `assistant_key`        | Assistant key (copied from thread)                                |
| `user_id`              | Nullable user id (null for assistant messages)                    |
| `role`                 | Enum (`user`, `assistant`)                                        |
| `content`              | Text/JSON payload                                                 |
| `content_type`         | Enum (`text`, `json`)                                             |
| `sequence`             | Int ordering within thread                                        |
| `status`               | Enum (`processing`, `completed`, `failed`)                        |
| `failed_reason`        | Nullable failure reason when `status=failed`                      |
| `model`                | LLM model used                                                    |
| `tokens_in/tokens_out` | Nullable token counts                                             |
| `provider_response_id` | Optional provider identifier                                      |
| `is_memory_checked`    | Boolean flag indicating whether the extractor reviewed this msg   |
| `metadata`             | JSON metadata (includes `tool_run_ids` when present)              |
| `created_at/updated_at/deleted_at`| Timestamps + soft deletes                              |

Indexes: `thread_id, sequence`; `thread_id`; `user_id`.

## Lifecycle Rules
- Assistant messages are created in `processing` status and transition to `completed` or `failed`.
- `ThreadMessageService::sendUserMessage` ensures no other assistant message is `processing` before accepting a new user message.
- `AiMessageService::markStatus` sets `failed_reason` when status becomes `FAILED`.
- Messages remain `is_memory_checked = false` until the memory assistant processes them (runs every time four unchecked completed messages accumulate).

## Assistant Response Flow
1. User message recorded (`status=completed`), assistant placeholder created (`status=processing`).
2. Response generation is executed inline or via `RunAssistantResponseJob` based on `$dispatchResponse`.
3. `AssistantResponseService`:
   - Builds thread state (messages, prompt, tools, memories).
   - Calls Prism Text API.
   - Persists assistant message content, metadata, token usage.
   - Records tool runs and attaches tool run ids to metadata.
   - Marks failures as `FAILED` with a reason; job `failed()` also marks failures.
4. After persistence, `AssistantResponseService`:
   - Evaluates whether the thread summary assistant should run (unchanged behavior).
   - Counts unchecked (`is_memory_checked = false`) completed messages and dispatches `PushMemoryExtractorAssistantJob` whenever the configured threshold (`atlas-nexus.memory.pending_message_count`, default `4`) is reached; the job sends the new messages plus existing memories to the dedicated `memory assistant`, deduplicates outputs, and appends them to `ai_threads.memories`.

## Services
- `AiThreadService` — CRUD and cascade delete of messages/tool runs.
- `AiMessageService` — CRUD + status helpers; auto-applies `group_id` from the thread when absent.
- `ThreadMessageService` — records user/assistant messages and kicks off generation.
- `ThreadStateService` — builds conversation state including thread-level memories and tools.
- `ThreadMemoryService` — manages the `ai_threads.memories` payload and user-level lookups.
- `ThreadMemoryExtractionService` — orchestrates the background extraction assistant and message flag updates.
