<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Data;

use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\ResolvedAssistant;
use Atlas\Nexus\Services\Tools\ProviderToolDefinition;
use Atlas\Nexus\Services\Tools\ToolDefinition;
use Illuminate\Support\Collection;

use function collect;

/**
 * Aggregates the contextual information available to a thread, including prompts, memories, tool access, and chat history.
 *
 * @property Collection<int, AiMessage> $messages
 * @property Collection<int, AiMemory> $memories
 * @property Collection<int, ToolDefinition> $tools
 * @property Collection<int, ProviderToolDefinition> $providerTools
 * @property string|null $systemPrompt
 */
class ThreadState
{
    /** @var Collection<int, ProviderToolDefinition> */
    public readonly Collection $providerTools;

    /**
     * @param  Collection<int, AiMessage>  $messages
     * @param  Collection<int, AiMemory>  $memories
     * @param  Collection<int, ToolDefinition>  $tools
     * @param  Collection<int, ProviderToolDefinition>  $providerTools
     */
    public function __construct(
        public readonly AiThread $thread,
        public readonly ResolvedAssistant $assistant,
        public readonly ?string $prompt,
        public readonly Collection $messages,
        public readonly Collection $memories,
        public readonly Collection $tools,
        public readonly ?string $systemPrompt = null,
        ?Collection $providerTools = null
    ) {
        $this->providerTools = $providerTools ?? collect();
    }
}
