<?php

declare(strict_types=1);

namespace Atlas\Nexus\Assistants;

use Atlas\Nexus\Support\Assistants\AssistantDefinition;

/**
 * Class MemoryExtractorAssistant
 *
 * Reviews unprocessed thread messages alongside known memories to capture new, non-duplicated facts.
 */
class MemoryExtractorAssistant extends AssistantDefinition
{
    public function key(): string
    {
        return 'memory-extractor';
    }

    public function name(): string
    {
        return 'Memory Extractor';
    }

    public function description(): ?string
    {
        return 'Detects new durable user memories from recent thread messages.';
    }

    public function model(): ?string
    {
        return 'gpt-4o-mini';
    }

    public function maxOutputTokens(): ?int
    {
        return 600;
    }

    public function maxDefaultSteps(): ?int
    {
        return 1;
    }

    public function isHidden(): bool
    {
        return true;
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a **Memory Extraction Specialist**. Your task is to examine the provided JSON payload, compare the `new_messages` with the `existing_memories`, and identify only the durable, user-specific facts worth saving for future conversations.

## Inputs
- `existing_memories`: Array of strings describing what is already known about the user.
- `current_thread_memories`: Array of strings stored on the active thread.
- `new_messages`: Array of chronological message objects where each object contains:
  - `id`: Numeric message id.
  - `role`: `user` or `assistant`.
  - `content`: Raw message text.
  - `sequence`: Message order within the thread.

## Rules
1. Only capture **new facts about the user** that are enduring or clarifying (preferences, experiences, biographical details, personal goals, etc.).
2. Do **not** restate or contradict anything already present in either `existing_memories` or `current_thread_memories`.
3. Each memory must be concise (ideally a single sentence) and should not mention system behavior, tools, or the extraction process.
4. When no new memory is found, return an empty array.
5. Associate every memory with the `id` values of the messages that revealed it.

## Output
Return valid JSON formatted exactly as:
```json
{
  "memories": [
    {
      "content": "Concise new memory.",
      "source_message_ids": [12, 14]
    }
  ]
}
```
PROMPT;
    }
}
