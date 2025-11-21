# PRD — Assistants & Prompts

Defines assistant personas, defaults, and versioned system prompts.

## Table of Contents
- [Assistants](#assistants)
- [Prompts](#prompts)
- [Assistant ↔ Prompt Behavior](#assistant--prompt-behavior)
- [Assistant ↔ Tool Mapping](#assistant--tool-mapping)
- [Service Responsibilities](#service-responsibilities)

## Assistants
Table: `ai_assistants`

| Field                | Description                                                 |
|----------------------|-------------------------------------------------------------|
| `id`                 | Primary key                                                 |
| `slug`               | Unique identifier                                           |
| `name`               | Display name                                                |
| `description`        | Optional description                                        |
| `default_model`      | Default LLM model id                                        |
| `temperature`        | Nullable float (0–1)                                        |
| `top_p`              | Nullable float (0–1)                                        |
| `max_output_tokens`  | Nullable int (token cap)                                    |
| `current_prompt_id`  | Nullable FK to active prompt (no DB constraint)             |
| `is_active`          | Boolean                                                     |
| `tools`              | JSON array of allowed tool keys (nullable)                  |
| `metadata`           | JSON metadata                                               |
| `created_at/updated_at/deleted_at` | Timestamps + soft deletes                     |

## Prompts
Table: `ai_prompts`

| Field               | Description                                                 |
|---------------------|-------------------------------------------------------------|
| `id`                | Primary key                                                 |
| `user_id`           | Optional author/tracker                                     |
| `assistant_id`      | Assistant owner (no FK)                                     |
| `version`           | Int version (enforce uniqueness per assistant in code)      |
| `label`             | Optional label                                              |
| `system_prompt`     | Text content                                                |
| `variables_schema`  | JSON schema describing variables                            |
| `is_active`         | Boolean                                                     |
| `created_at/updated_at/deleted_at` | Timestamps + soft deletes                    |

## Assistant ↔ Prompt Behavior
- `current_prompt_id` on assistants points to the active prompt; thread state resolves prompt as `thread.prompt ?? assistant.currentPrompt`.
- Multiple prompts per assistant are allowed; versions must be unique per assistant in code.
- Prompt selection may be overridden per thread via `prompt_id`.

## Assistant ↔ Tool Mapping
Assistant tool availability is configured via the `ai_assistants.tools` JSON array of tool keys (e.g., `["memory","web_search"]`).

Rules:
- Keys are normalized to unique strings; null/empty means no tools.
- Built-in tools (memory, web_search, thread_manager) are registered automatically when enabled in config; thread state filters to registered tool keys.
- Seeders provision built-in assistants/prompts where required (e.g., web search summarizer, thread manager).

## Service Responsibilities
- `AiAssistantService` — CRUD + tool key sync helpers.
- `AiPromptService` — CRUD + versioning constraints enforced in code.
- `NexusSeederService` executes configured seeders (e.g., WebSearchAssistantSeeder, ThreadManagerAssistantSeeder) to provision built-in assistants/prompts.
