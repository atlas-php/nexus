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
1. Create a class that extends `Atlas\Nexus\Support\Assistants\AssistantDefinition` and implement the required methods (`key`, `name`, `systemPrompt`, defaults, tools, etc.).
2. Register the class inside `config/atlas-nexus.php` under `assistants.definitions`.
3. Reference the assistant by its `key` everywhere else (threads, messages, tool runs, memories).

## Register Tools
1. Implement a `NexusTool` handler with a fixed tool key (e.g., `memory`, `calendar_lookup`).
2. Register the tool via `atlas-nexus.tools.registry` (`key => handler_class`) or rely on built-ins.
3. Include the tool key in the assistant definition’s `tools()` return value.

## Start a Thread
1. Create an `ai_threads` row with `assistant_key`, `user_id`, optional `group_id`, `type=user`, `status=open`.

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
- `ThreadStateService` exposes applicable memories for prompts (use `{MEMORY.CONTEXT}` when desired); assistant message metadata includes `memory_ids`.
