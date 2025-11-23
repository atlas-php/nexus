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
Table: `ai_assistant_prompts`

| Field               | Description                                                 |
|---------------------|-------------------------------------------------------------|
| `id`                | Primary key                                                 |
| `assistant_id`      | Owning assistant id                                         |
| `user_id`           | Optional author/tracker                                     |
| `version`           | Int version (auto-incremented per assistant)                |
| `original_prompt_id`| Self-reference to the first prompt in the lineage           |
| `system_prompt`     | Text content                                                |
| `is_active`         | Boolean                                                     |
| `created_at/updated_at/deleted_at` | Timestamps + soft deletes                    |

## Prompt Variables
- `system_prompt` supports placeholders (e.g., `{USER.NAME}`, `{USER.EMAIL}`) that are replaced before the request is sent to the LLM.
- Default providers resolve from the thread's authenticatable user when the `users` table is available.
- Additional providers can be registered via `atlas-nexus.prompts.variables`; each receives a `PromptVariableContext` containing the thread, assistant, prompt, and user. Use `PromptVariableGroup` to map multiple keys within one class.
- Inline overrides may also be merged by calling `PromptVariableService::apply($prompt, $context, ['TEAM.NAME' => 'Atlas'])`.

## Assistant ↔ Prompt Behavior
- `current_prompt_id` on assistants points to the active prompt; thread state resolves prompt as `thread.prompt ?? assistant.currentPrompt` and ignores prompts that belong to other assistants.
- Prompts are *not* global — each row belongs to a single assistant via `assistant_id`, and a prompt cannot be shared between assistants.
- `original_prompt_id` links all versions for an assistant; the initial prompt references its own id. `AiAssistantPromptService::create` automatically assigns version `1` for a new assistant, and `AiAssistantPromptService::edit($prompt, $data)` always clones a new version (no inline updates).
- Prompt selection may be overridden per thread via `assistant_prompt_id`, but consumer code must reference a prompt that belongs to the same assistant.

## Assistant ↔ Tool Mapping
Assistant tool availability is configured via the `ai_assistants.tools` JSON array of tool keys (e.g., `["memory","web_search"]`).

Rules:
- Keys are normalized to unique strings; null/empty means no tools.
- Built-in tools (memory, web_search, thread_fetcher, thread_updater) are registered automatically when enabled in config; thread state filters to registered tool keys.
- Seeders provision built-in assistants/prompts where required (e.g., web search summarizer, thread manager).

## Service Responsibilities
- `AiAssistantService` — CRUD + tool key sync helpers.
- `AiAssistantPromptService` — CRUD plus lineage-aware editing; `create()` requires an `assistant_id` and `edit()` always creates a new version for that assistant.
- `NexusSeederService` executes configured seeders (e.g., WebSearchAssistantSeeder, ThreadManagerAssistantSeeder) to provision built-in assistants/prompts.
