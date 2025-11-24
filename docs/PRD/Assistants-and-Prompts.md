# Assistants

Nexus no longer stores assistants or prompts in database tables. Instead, every assistant is defined via a PHP class that extends `Atlas\Nexus\Support\Assistants\AssistantDefinition` and is registered inside `config/atlas-nexus.php`.

## Configuration

```php
'assistants' => [
    \Atlas\Nexus\Assistants\GeneralAssistant::class,
    \Atlas\Nexus\Assistants\HumanAssistant::class,
    \Atlas\Nexus\Assistants\ThreadManagerAssistant::class,
    \Atlas\Nexus\Assistants\MemoryExtractorAssistant::class,
    // \App\Nexus\Assistants\CustomAssistant::class,
],
```

Nexus ships with the three `Atlas\Nexus\Assistants\*` defaults shown above so every installation has a working conversational, human-style, and summarization assistant immediately. Consumers may remove or replace any of them by editing the array and pointing to their own `AssistantDefinition` implementations.

Each definition class must implement:

| Method | Purpose |
| ------ | ------- |
| `key()` | Unique assistant key used by all runtime tables (`assistant_key` columns). |
| `name()` / `description()` | Display metadata for UIs or logs. |
| `systemPrompt()` | Raw system prompt text that `ThreadStateService` renders with prompt variables. |
| `model()` / `temperature()` / `topP()` / `maxOutputTokens()` | Provider defaults applied by `AssistantResponseService`. |
| `maxDefaultSteps()` | Default Prism `max_steps` value per assistant; overrides config defaults. |
| `reasoning()` | Optional provider-specific reasoning options (e.g., OpenAI `reasoning` payload). Return `null` to disable reasoning. |
| `isActive()` / `isHidden()` | Toggle availability and optionally hide assistants from user-facing lists. |
| `tools()` | Array of tool keys or keyed configuration arrays. Each entry may be a simple string (`'calendar_lookup'`) or `['thread_fetcher' => ['mode' => 'summary']]` to pass options to the tool handler. |
| `providerTools()` | Provider-native tool declarations with assistant-owned options (e.g., `['web_search' => ['filters' => ['allowed_domains' => ['atlasphp.com']]]]`). Structure mirrors `tools()` so each assistant can supply its own OpenAI/Reka/etc. tool parameters. |
| `metadata()` | Arbitrary assistant metadata exposed to consumers. |

The base class normalizes arrays, deduplicates tool keys, and exposes helper setters (`promptIsActive`, `promptUserId`). Tool and provider tool declarations may be strings or associative arrays; any configuration arrays are preserved and supplied to the runtime services so every assistant can own its own tool options.

### Reasoning (OpenAI)

When the default provider is OpenAI, Nexus forwards each assistant's `reasoning()` payload straight into Prism so you can dial in OpenAI's native reasoning behaviors (effort, budget, etc.). The configuration is a plain associative array:

```php
public function reasoning(): ?array
{
    return [
        'effort' => 'low',      // low, medium, or high
        'budget_tokens' => 512, // optional token budget
    ];
}
```

If the active provider is not OpenAI, the payload is ignored. The bundled `GeneralAssistant` ships with `['effort' => 'low']` so every install benefits from a deterministic baseline, and consumers can override this per assistant to match their own usage targets.

### Tool Configuration Examples

```php
public function tools(): array
{
    return [
        'calendar_lookup' => ['allowed_calendars' => ['sales', 'success']],
        ['key' => 'thread_fetcher', 'mode' => 'summary', 'include_messages' => true],
    ];
}

public function providerTools(): array
{
    return [
        'web_search' => [
            'filters' => [
                'allowed_domains' => [
                    'inveloapp.com',
                    'helpdesk.inveloapp.com',
                    'blog.inveloapp.com',
                ],
            ],
        ],
        'file_search' => [
            'vector_store_ids' => ['vs_main_docs', 'vs_support_kb'],
        ],
    ];
}
```

## Runtime Columns

Because assistants live in code, the other tables simply store an `assistant_key` string:

| Table | Column |
| ----- | ------ |
| `ai_threads` | `assistant_key` |
| `ai_messages` | `assistant_key` |
| `ai_message_tools` | `assistant_key` |

Thread creation is now a matter of setting `assistant_key` to one of the configured keys; the rest of the assistant metadata is resolved on demand through `AssistantRegistry`.
