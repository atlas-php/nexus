<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Chat;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiTool;
use Illuminate\Support\Collection;

/**
 * Aggregates the contextual information available to a thread, including prompts, memories, tool access, and chat history.
 *
 * @property Collection<int, AiMessage> $messages
 * @property Collection<int, AiMemory> $memories
 * @property Collection<int, AiTool> $tools
 */
class ThreadState
{
    public function __construct(
        public readonly AiThread $thread,
        public readonly AiAssistant $assistant,
        public readonly ?AiPrompt $prompt,
        public readonly Collection $messages,
        public readonly Collection $memories,
        public readonly Collection $tools
    ) {}
}
