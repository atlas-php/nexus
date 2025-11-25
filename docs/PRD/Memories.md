# PRD — Memories

Nexus captures durable user facts as thread-level memories that can be reused across conversations without exposing a mutable table to consuming apps.

## Table of Contents
- [Thread Memory Payload](#thread-memory-payload)
- [Extraction Flow](#extraction-flow)
- [Usage](#usage)
- [Services](#services)

## Thread Memory Payload
- Stored on `ai_threads.memories` as a JSON array.
- Each entry contains:
  - `content` — concise natural-language statement.
  - `thread_id` — automatically stamped with the owning thread id.
  - `created_at` — ISO8601 timestamp indicating when the memory was saved.
- All writes go through `ThreadMemoryService::appendMemories`, which deduplicates entries by normalized content and ensures IDs/timestamps are set.

## Extraction Flow
1. Every message defaults to `is_memory_checked = false`.
2. After each assistant reply, `AssistantResponseService` counts unchecked, completed messages.
3. When the configured threshold (`atlas-nexus.memory.pending_message_count`, default `4`) of unchecked messages exists, it dispatches `PushMemoryExtractorAssistantJob` (one at a time per thread; tracked via `thread.metadata.memory_job_pending`).
4. The job collects all unchecked completed messages, current thread memories, and the user's aggregated memories across threads.
5. `ThreadMemoryExtractionService` sends this payload to the hidden `memory assistant`, which must return JSON containing each new memory object with `content` (and optional `importance`).
6. `ThreadMemoryService` appends any new, non-duplicated entries to `ai_threads.memories`.
7. All processed messages are marked `is_memory_checked = true`.

Failures leave `is_memory_checked` untouched so the next dispatch will retry once the error is resolved.

## Usage
- `ThreadStateService` exposes `ThreadMemoryService::memoriesForThread` so prompts can opt into `{MEMORY.CONTEXT}`.
- `ThreadMemoryService::userMemories($userId)` merges memories across all threads for display or auditing.
- Clearing a thread via `AiThreadService` automatically removes its stored memories because they live on the thread record.

## Services
- `ThreadMemoryService` — normalizes, deduplicates, and appends thread memories; exposes per-user listings.
- `ThreadMemoryExtractionService` — orchestrates the `memory assistant` requests and message flag updates.
- `PushMemoryExtractorAssistantJob` — queued job that triggers extraction when unchecked message thresholds are met.
