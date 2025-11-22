<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Chat;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Prompts\PromptSnapshot;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Illuminate\Support\Collection;

use function collect;

/**
 * Aggregates the contextual information available to a thread, including prompts, memories, tool access, and chat history.
 *
 * @property Collection<int, AiMessage> $messages
 * @property Collection<int, AiMemory> $memories
 * @property Collection<int, ToolDefinition> $tools
 * @property Collection<int, \Atlas\Nexus\Support\Tools\ProviderToolDefinition> $providerTools
 * @property PromptSnapshot|null $promptSnapshot
 * @property string|null $systemPrompt
 */
class ThreadState
{
    /** @var Collection<int, \Atlas\Nexus\Support\Tools\ProviderToolDefinition> */
    public readonly Collection $providerTools;

    /**
     * @param  Collection<int, AiMessage>  $messages
     * @param  Collection<int, AiMemory>  $memories
     * @param  Collection<int, ToolDefinition>  $tools
     * @param  Collection<int, \Atlas\Nexus\Support\Tools\ProviderToolDefinition>  $providerTools
     */
    public function __construct(
        public readonly AiThread $thread,
        public readonly AiAssistant $assistant,
        public readonly ?AiPrompt $prompt,
        public readonly Collection $messages,
        public readonly Collection $memories,
        public readonly Collection $tools,
        public readonly ?PromptSnapshot $promptSnapshot = null,
        public readonly ?string $systemPrompt = null,
        ?Collection $providerTools = null
    ) {
        $this->providerTools = $providerTools ?? collect();
    }
}
