# PRD — Example Usage

Scenario-driven overview of how consumers create assistants, start threads, send messages, and leverage tools/memories.

## Table of Contents
- [Create an Assistant & Prompt](#create-an-assistant--prompt)
- [Register Tools](#register-tools)
- [Start a Thread](#start-a-thread)
- [Send a Message](#send-a-message)
- [Inline vs Queued Responses](#inline-vs-queued-responses)
- [Track Tool Runs](#track-tool-runs)
- [Manage Memories](#manage-memories)

## Create an Assistant & Prompt
1. Create an assistant row with defaults (`slug`, `default_model`, optional `temperature/top_p/max_output_tokens`).
2. Create a prompt version via `AiPromptService::create` with `assistant_id` and `system_prompt` (the service auto-assigns the next version and seeds `original_prompt_id` for lineage tracking).
3. Set `current_prompt_id` on the assistant to the active prompt. Subsequent edits must call `AiPromptService::edit($prompt, $data)`, which always clones a new row instead of updating in place.

## Register Tools
1. Implement a `NexusTool` handler with a fixed tool key (e.g., `memory`, `calendar_lookup`).
2. Register the tool via `atlas-nexus.tools.registry` (`key => handler_class`) or rely on built-ins.
3. Add the tool key to the assistant `tools` array; run `atlas:nexus:seed` to add the Memory key when enabled.

## Start a Thread
1. Create `ai_threads` row with `assistant_id`, `user_id`, optional `group_id`, `type=user`, `status=open`.
2. Optionally set `prompt_id` to override the assistant’s current prompt.

## Send a Message
1. Call `ThreadMessageService::sendUserMessage($thread, $content, $userId, $contentType, $dispatchResponse)`.
2. Service ensures no assistant message is currently processing.
3. Creates user message (`status=completed`) and assistant placeholder (`status=processing`).
4. Touches `last_message_at` on the thread.

## Inline vs Queued Responses
- `$dispatchResponse=true` (default): dispatches `RunAssistantResponseJob`; queue name can be set via `atlas-nexus.responses.queue`.
- `$dispatchResponse=false`: runs `AssistantResponseService` inline.
- Both paths must mark assistant messages as `FAILED` on exceptions.

## Track Tool Runs
- `AssistantResponseService` creates `ai_tool_runs` for tool calls/results; `group_id` inherited from the thread.
- Tool handlers can log via `ToolRunLogger` when implementing `ToolRunLoggingAware`.
- Tool run metadata stores Prism `tool_call_id` and `tool_call_result_id` when available.

## Manage Memories
- `MemoryTool` (seeded) allows assistants to add/update/fetch/delete memories via tool calls.
- `AiMemoryService::saveForThread` stores memory with `group_id` and owner/assistant scope.
- `ThreadStateService` injects applicable memories into request context; assistant message metadata includes `memory_ids`.
