<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Prompts;

use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
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
        private ?ResolvedAssistant $assistantOverride = null,
        private ?string $promptOverride = null,
        private ?Authenticatable $user = null,
        private ?AiThread $threadOverride = null
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
        return $this->resolvedThread();
    }

    public function assistant(): ResolvedAssistant
    {
        return $this->assistantOverride ?? $this->threadState->assistant;
    }

    public function prompt(): ?string
    {
        return $this->promptOverride ?? $this->threadState->prompt;
    }

    public function user(): ?Authenticatable
    {
        if ($this->userLoaded) {
            return $this->user;
        }

        $this->userLoaded = true;

        try {
            $relation = $this->resolvedThread()->user();
            $relatedModel = $relation->getRelated();

            if (! Schema::hasTable($relatedModel->getTable())) {
                return null;
            }

            $loaded = $this->resolvedThread()->getRelationValue('user');

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

    private function resolvedThread(): AiThread
    {
        return $this->threadOverride ?? $this->threadState->thread;
    }
}
