# Assistants

Nexus no longer stores assistants or prompts in database tables. Instead, every assistant is defined via a PHP class that extends `Atlas\Nexus\Support\Assistants\AssistantDefinition` and is registered inside `config/atlas-nexus.php`.

## Configuration

```php
'assistants' => [
    \App\Nexus\Assistants\GeneralAssistant::class,
    \App\Nexus\Assistants\ThreadManagerAssistant::class,
],
```

Each definition class must implement:

| Method | Purpose |
| ------ | ------- |
| `key()` | Unique assistant key used by all runtime tables (`assistant_key` columns). |
| `name()` / `description()` | Display metadata for UIs or logs. |
| `systemPrompt()` | Raw system prompt text that `ThreadStateService` renders with prompt variables. |
| `defaultModel()` / `temperature()` / `topP()` / `maxOutputTokens()` | Provider defaults applied by `AssistantResponseService`. |
| `tools()` | Array of regular tool keys the assistant may call. |
| `providerTools()` | Provider-native tool configuration passed straight to Prism. |
| `metadata()` | Arbitrary assistant metadata exposed to consumers. |

The base class normalizes arrays and exposes helper setters (`promptIsActive`, `promptUserId`) for future overrides.

## Runtime Columns

Because assistants live in code, the other tables simply store an `assistant_key` string:

| Table | Column |
| ----- | ------ |
| `ai_threads` | `assistant_key` |
| `ai_messages` | `assistant_key` |
| `ai_memories` | `assistant_key` (nullable) |
| `ai_tool_runs` | `assistant_key` |

Thread creation is now a matter of setting `assistant_key` to one of the configured keys; the rest of the assistant metadata is resolved on demand through `AssistantRegistry`.
