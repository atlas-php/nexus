<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Prompts;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Chat\ThreadState;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Class PromptVariableContext
 *
 * Carries the thread, prompt, assistant, and user context required to resolve prompt variables safely.
 */
class PromptVariableContext
{
    private bool $userLoaded;

    public function __construct(
        public readonly ThreadState $threadState,
        public readonly AiAssistantPrompt $prompt,
        public readonly AiAssistant $assistant,
        private ?Authenticatable $user = null
    ) {
        $this->userLoaded = $user !== null;
    }

    /**
     * Access the aggregated thread state used when resolving prompt variables.
     */
    public function threadState(): ThreadState
    {
        return $this->threadState;
    }

    public function thread(): AiThread
    {
        return $this->threadState->thread;
    }

    public function assistant(): AiAssistant
    {
        return $this->assistant;
    }

    public function prompt(): AiAssistantPrompt
    {
        return $this->prompt;
    }

    public function user(): ?Authenticatable
    {
        if ($this->userLoaded) {
            return $this->user;
        }

        $this->userLoaded = true;

        try {
            $relation = $this->threadState->thread->user();
            $relatedModel = $relation->getRelated();

            if (! Schema::hasTable($relatedModel->getTable())) {
                return null;
            }

            $loaded = $this->threadState->thread->getRelationValue('user');

            if ($loaded instanceof Authenticatable) {
                $this->user = $loaded;

                return $this->user;
            }

            $this->user = $relation->first();
        } catch (Throwable) {
            $this->user = null;
        }

        return $this->user;
    }
}
