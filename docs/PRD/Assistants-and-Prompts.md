# Agents

Nexus no longer stores agents or prompts in database tables. Instead, every agent is defined via a PHP class that extends `Atlas\Nexus\Services\Agents\AgentDefinition` and is registered inside `config/atlas-nexus.php`.

## Configuration

```php
'agents' => [
    \Atlas\Nexus\Services\Agents\Definitions\GeneralAgent::class,
    \Atlas\Nexus\Services\Agents\Definitions\HumanAgent::class,
    \Atlas\Nexus\Services\Agents\Definitions\ThreadSummaryAgent::class,
    \Atlas\Nexus\Services\Agents\Definitions\MemoryAgent::class,
    // \App\Nexus\Agents\CustomAgent::class,
],
```

Nexus ships with the `Atlas\Nexus\Services\Agents\Definitions\*` defaults shown above so every installation has a working conversational, human-style, and summarization agent immediately. Consumers may remove or replace any of them by editing the array and pointing to their own `AgentDefinition` implementations.

Each definition class must implement:

| Method | Purpose |
| ------ | ------- |
| `key()` | Unique agent key used by all runtime tables (`assistant_key` columns). |
| `name()` / `description()` | Display metadata for UIs or logs. |
| `systemPrompt()` | Raw system prompt text that `ThreadStateService` renders with prompt variables. |
| `contextPrompt()` | Optional agent-authored context prompt template rendered into a kickoff agent message for new user threads. Return `null` to skip context prompts. |
| `isContextAvailable(AiThread $thread)` | Guards whether the context prompt should be attached. Defaults to `false`; agents may inspect the thread (and fetch any supporting data they need) and return `true` when the context message adds value. |
| `model()` / `temperature()` / `topP()` / `maxOutputTokens()` | Provider defaults applied by `AssistantResponseService`. |
| `maxDefaultSteps()` | Default Prism `max_steps` value per agent; overrides config defaults. |
| `reasoning()` | Optional provider-specific reasoning options (e.g., OpenAI `reasoning` payload). Return `null` to disable reasoning. |
| `isActive()` / `isHidden()` | Toggle availability and optionally hide agents from user-facing lists. |
| `tools()` | Array of tool keys or keyed configuration arrays. Each entry may be a simple string (`'calendar_lookup'`) or `['fetch_more_context' => ['limit' => 10]]` to pass options to the tool handler. |
| `providerTools()` | Provider-native tool declarations with agent-owned options (e.g., `['web_search' => ['filters' => ['allowed_domains' => ['atlasphp.com']]]]`). Structure mirrors `tools()` so each agent can supply its own OpenAI/Reka/etc. tool parameters. |
| `metadata()` | Arbitrary agent metadata exposed to consumers. |

The base class normalizes arrays, deduplicates tool keys, and exposes helper setters (`promptIsActive`, `promptUserId`). Tool and provider tool declarations may be strings or associative arrays; any configuration arrays are preserved and supplied to the runtime services so every agent can own its own tool options.

### Reasoning (OpenAI)

When the default provider is OpenAI, Nexus forwards each agent's `reasoning()` payload straight into Prism so you can dial in OpenAI's native reasoning behaviors (effort, budget, etc.). The configuration is a plain associative array:

```php
public function reasoning(): ?array
{
    return [
        'effort' => 'low',      // low, medium, or high
        'budget_tokens' => 512, // optional token budget
    ];
}
```

If the active provider is not OpenAI, the payload is ignored. The bundled `GeneralAgent` ships with `['effort' => 'low']` so every install benefits from a deterministic baseline, and consumers can override this per agent to match their own usage targets.

### Tool Configuration Examples

```php
public function tools(): array
{
    return [
        'calendar_lookup' => ['allowed_calendars' => ['sales', 'success']],
        ['key' => 'fetch_more_context', 'limit' => 10],
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

Because agents live in code, the other tables simply store an `assistant_key` string:

| Table | Column |
| ----- | ------ |
| `ai_threads` | `assistant_key` |
| `ai_messages` | `assistant_key` |
| `ai_message_tools` | `assistant_key` |

Thread creation is now a matter of setting `assistant_key` to one of the configured keys; the rest of the agent metadata is resolved on demand through `AgentRegistry`.
