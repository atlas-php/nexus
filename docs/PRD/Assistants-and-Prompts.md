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
| `is_hidden`          | Boolean for internal-only assistants                        |
| `tools`              | JSON array of allowed tool keys (nullable)                  |
| `metadata`           | JSON metadata                                               |
| `created_at/updated_at/deleted_at` | Timestamps + soft deletes                     |

## Prompts
Table: `ai_prompts`

| Field               | Description                                                 |
|---------------------|-------------------------------------------------------------|
| `id`                | Primary key                                                 |
| `user_id`           | Optional author/tracker                                     |
| `version`           | Int version (auto-incremented per lineage)                  |
| `original_prompt_id`| Self-reference to the first prompt in the lineage           |
| `label`             | Optional label                                              |
| `system_prompt`     | Text content                                                |
| `is_active`         | Boolean                                                     |
| `created_at/updated_at/deleted_at` | Timestamps + soft deletes                    |

## Prompt Variables
- `system_prompt` supports placeholders (e.g., `{USER.NAME}`, `{USER.EMAIL}`) that are replaced before the request is sent to the LLM.
- Default providers resolve from the thread's authenticatable user when the `users` table is available.
- Additional providers can be registered via `atlas-nexus.prompts.variables`; each receives a `PromptVariableContext` containing the thread, assistant, prompt, and user. Use `PromptVariableGroup` to map multiple keys within one class.
- Inline overrides may also be merged by calling `PromptVariableService::apply($prompt, $context, ['TEAM.NAME' => 'Atlas'])`.

## Assistant ↔ Prompt Behavior
- `current_prompt_id` on assistants points to the active prompt; thread state resolves prompt as `thread.prompt ?? assistant.currentPrompt`.
- Prompts are global records. Assistants point to their active prompt via `current_prompt_id`.
- `original_prompt_id` links all versions of a prompt lineage; the initial prompt references its own id. Use `AiPromptService::edit($prompt, $data, $createNewVersion = true)` to branch a new version (the next integer version is calculated automatically).
- Prompt selection may be overridden per thread via `prompt_id`.

## Assistant ↔ Tool Mapping
Assistant tool availability is configured via the `ai_assistants.tools` JSON array of tool keys (e.g., `["memory","web_search"]`).

Rules:
- Keys are normalized to unique strings; null/empty means no tools.
- Built-in tools (memory, web_search, thread_manager) are registered automatically when enabled in config; thread state filters to registered tool keys.
- Seeders provision built-in assistants/prompts where required (e.g., web search summarizer, thread manager).

## Service Responsibilities
- `AiAssistantService` — CRUD + tool key sync helpers.
- `AiPromptService` — CRUD plus lineage-aware editing (inline updates or auto-versioned clones via `edit()`).
- `NexusSeederService` executes configured seeders (e.g., WebSearchAssistantSeeder, ThreadManagerAssistantSeeder) to provision built-in assistants/prompts.
