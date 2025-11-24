# PRD — Tools & Tool Runs

Defines how Nexus registers code-defined tools, assigns them to assistants, and records execution results.

## Table of Contents
- [Tools](#tools)
- [Assistant Tool Keys](#assistant-tool-keys)
- [Tool Runs](#tool-runs)
- [Execution Semantics](#execution-semantics)
- [Services](#services)

## Tools
Tools are **code-defined**. Each tool implements `NexusTool`, declares a fixed **key** (e.g., `thread_fetcher`), and is registered inside `ToolRegistry`.

- Built-in tools are registered automatically:
  - `thread_search` — search the assistant/user’s threads by title, summary, long summary, keywords, message body, or the user’s name.
  - `thread_fetcher` — fetch one or many threads (IDs provided by the search tool or other signals) and return summaries, keywords, and ordered message transcripts.
  - `thread_updater` — update thread title/summary or auto-generate them from conversation context.
- Additional tools can be registered at runtime by resolving `ToolRegistry` from the container and calling `register(new ToolDefinition('custom', CustomTool::class))`.
- Only tools with resolvable handler classes are exposed to Prism.
- `ToolRegistry::available()` exposes all registered tool definitions (for UI listing/checkboxes when creating assistants).
- Handlers may implement `ConfigurableTool` to receive assistant-owned configuration arrays. Return keyed entries from `AssistantDefinition::tools()` (e.g., `['calendar_lookup' => ['allowed_calendars' => ['sales']]]`) to pass these options to the handler before it is converted into a Prism tool.
- `AssistantDefinition::providerTools()` mirrors this structure for provider-native tools so each assistant controls its own provider options (vector store ids, allowed domains, etc.) without touching global config.
- Example configuration:

```php
public function tools(): array
{
    return [
        'thread_search',
        'calendar_lookup' => ['allowed_calendars' => ['sales', 'success']],
    ];
}

public function providerTools(): array
{
    return [
        'web_search' => [
            'filters' => [
                'allowed_domains' => [
                    'docs.example.com',
                    'support.example.com',
                    'blog.example.com',
                ],
            ],
        ],
        'file_search' => [
            'vector_store_ids' => ['vs_product_docs', 'vs_release_notes'],
        ],
    ];
}
```
- Provider-native web tooling (e.g., `'web_search'`) is now configured exclusively through `providerTools()`; Nexus no longer ships a code-defined web search handler.

## Assistant Tool Keys
Assistant definition classes control which tool keys are available by overriding `tools()`. Thread state filters these keys against the registered tool set; built-in tools are registered automatically when enabled.

## Tool Runs
Table: `ai_message_tools`

| Field                   | Description                                                     |
|-------------------------|-----------------------------------------------------------------|
| `id`                    | Primary key                                                     |
| `group_id`              | Optional tenant/account grouping (inherits from thread)         |
| `tool_key`              | Registered tool key                                             |
| `assistant_key`         | Assistant key that initiated the run                            |
| `thread_id`             | Thread owning the run                                           |
| `assistant_message_id`  | Assistant message that initiated the call                       |
| `call_index`            | Int call order within response                                  |
| `input_args`            | JSON payload of arguments                                       |
| `status`                | Enum (`queued`,`running`,`succeeded`,`failed`)                  |
| `response_output`       | JSON output (normalized to array)                               |
| `metadata`              | JSON metadata (`tool_call_id`, `tool_call_result_id`, etc.)     |
| `error_message`         | Nullable failure details                                        |
| `started_at/finished_at`| Nullable timestamps                                             |
| `created_at/updated_at` | Timestamps                                                      |

Indexes: `tool_key`, `thread_id`, `assistant_message_id`.

## Execution Semantics
- `ThreadStateService` collects assistant tool keys, resolves registered tool handlers, injects thread state, and wires logging when handlers implement `ToolRunLoggingAware`.
- `AssistantResponseService` records tool calls and results, creating/updating `ai_message_tools`.
- `ToolRunLogger` can create and complete runs from within tool handlers.
- Runs inherit `group_id` from the parent thread when omitted in payloads.

## Services
- `ToolRegistry` — maintains available tool definitions (built-ins + configured mappings).
- `AiToolRunService` — CRUD + status helpers; auto-applies `group_id` from thread when absent.
- `ThreadStateService` — resolves registered tools matching an assistant's configured keys.
