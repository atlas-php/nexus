# PRD — Tools & Tool Runs

Defines how Nexus registers code-defined tools, assigns them to assistants, and records execution results.

## Table of Contents
- [Tools](#tools)
- [Assistant Tool Keys](#assistant-tool-keys)
- [Tool Runs](#tool-runs)
- [Execution Semantics](#execution-semantics)
- [Services](#services)

## Tools
Tools are **code-defined**. Each tool implements `NexusTool`, declares a fixed **key** (e.g., `memory`, `web_search`), and is registered inside `ToolRegistry`.

- Built-in tools are listed in `atlas-nexus.tools.registry` by default:
  - `memory` — add/update/fetch/delete thread-aware memories.
  - `web_search` — fetch website content and optionally summarize it inline via the built-in web summarizer assistant; options live under `atlas-nexus.tools.options.web_search`.
    - `allowed_domains` (array|null) restricts searches to configured domains; requests outside the list return `Error, this domain is not allowed to be searched`, and the tool description includes `Allowed domains: ...` for clarity.
  - `thread_fetcher` — search or fetch threads owned by the active assistant/user, including message listings and matching on the user’s name.
  - `thread_updater` — update thread title/summary or auto-generate them from conversation context; options live under `atlas-nexus.tools.options.thread_updater`.
- Additional tools can be registered through config (`atlas-nexus.tools.registry`) mapping `key => handler_class` (string or `['handler' => FQCN]`).
- Only tools with resolvable handler classes are exposed to Prism.
- `ToolRegistry::available()` exposes all registered tool definitions (for UI listing/checkboxes when creating assistants).

## Assistant Tool Keys
Field: `ai_assistants.tools`

| Field  | Description                                                   |
|--------|---------------------------------------------------------------|
| `tools` | JSON array of tool keys allowed for the assistant (nullable) |

Rules:
- Keys are normalized to unique strings; empty/null means no tools.
- Thread state filters keys against the registered tool set; built-in tools are registered automatically when enabled.

## Tool Runs
Table: `ai_tool_runs`

| Field                   | Description                                                     |
|-------------------------|-----------------------------------------------------------------|
| `id`                    | Primary key                                                     |
| `group_id`              | Optional tenant/account grouping (inherits from thread)         |
| `tool_key`              | Registered tool key                                             |
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
- `AssistantResponseService` records tool calls and results, creating/updating `ai_tool_runs`.
- `ToolRunLogger` can create and complete runs from within tool handlers.
- Runs inherit `group_id` from the parent thread when omitted in payloads.

## Services
- `ToolRegistry` — maintains available tool definitions (built-ins + configured mappings).
- `AiAssistantService` — CRUD + helpers for syncing assistant tool keys.
- `AiToolRunService` — CRUD + status helpers; auto-applies `group_id` from thread when absent.
- `ThreadStateService` — resolves registered tools matching an assistant's configured keys.
