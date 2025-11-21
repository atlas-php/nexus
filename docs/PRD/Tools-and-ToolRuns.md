# PRD — Tools & Tool Runs

Defines how Nexus registers tools, attaches them to assistants, and records execution results.

## Table of Contents
- [Tools](#tools)
- [Assistant Tools Pivot](#assistant-tools-pivot)
- [Tool Runs](#tool-runs)
- [Execution Semantics](#execution-semantics)
- [Services](#services)

## Tools
Table: `ai_tools`

| Field            | Description                                                |
|------------------|------------------------------------------------------------|
| `id`             | Primary key                                                |
| `slug`           | Unique tool identifier                                     |
| `name`           | Display name                                               |
| `description`    | Optional description                                       |
| `schema`         | JSON schema for tool arguments                             |
| `handler_class`  | Fully-qualified Laravel class implementing `NexusTool`     |
| `is_active`      | Boolean                                                    |
| `created_at/updated_at/deleted_at` | Timestamps + soft deletes                |

Rules:
- Tools with missing classes or inactive status are excluded from thread state.
- Built-in Memory tool is seeded via `atlas:nexus:seed` and attached when active.

## Assistant Tools Pivot
Table: `ai_assistant_tool`

| Field           | Description                                  |
|-----------------|----------------------------------------------|
| `id`            | Primary key                                  |
| `assistant_id`  | Assistant id                                 |
| `tool_id`       | Tool id                                      |
| `config`        | JSON config                                  |
| `created_at/updated_at` | Timestamps                           |

Uniqueness (`assistant_id`, `tool_id`) enforced in code.

## Tool Runs
Table: `ai_tool_runs`

| Field                   | Description                                                     |
|-------------------------|-----------------------------------------------------------------|
| `id`                    | Primary key                                                     |
| `group_id`              | Optional tenant/account grouping (inherits from thread)         |
| `tool_id`               | Tool id                                                         |
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

Indexes: `tool_id`, `thread_id`, `assistant_message_id`.

## Execution Semantics
- `ThreadStateService` prepares tools by instantiating handlers, injecting thread state, and wiring logging when handlers implement `ToolRunLoggingAware`.
- `AssistantResponseService` records tool calls and results, creating/updating `ai_tool_runs`.
- `ToolRunLogger` can create and complete runs from within tool handlers.
- Runs inherit `group_id` from the parent thread when omitted in payloads.

## Services
- `AiToolService` — CRUD for tool records.
- `AiAssistantToolService` — CRUD for assistant ↔ tool mappings.
- `AiToolRunService` — CRUD + status helpers; auto-applies `group_id` from thread when absent.
- `MemoryFeatureSeeder` — seeds Memory tool and attaches to assistants.
- `ThreadStateService` — filters tools to active, class-resolvable handlers.
