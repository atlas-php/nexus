<?php

declare(strict_types=1);

$defaultAgents = [
    \Atlas\Nexus\Services\Agents\Definitions\GeneralAgent::class,
    \Atlas\Nexus\Services\Agents\Definitions\HumanAgent::class,
    \Atlas\Nexus\Services\Agents\Definitions\ThreadSummaryAgent::class,
    \Atlas\Nexus\Services\Agents\Definitions\MemoryAgent::class,
];

$defaultPromptAttributes = [
    \Atlas\Nexus\Services\Prompts\Variables\ThreadPromptVariables::class,
    \Atlas\Nexus\Services\Prompts\Variables\UserPromptVariables::class,
    \Atlas\Nexus\Services\Prompts\Variables\MemoryPromptVariables::class,
];

$defaultThreadHooks = [
    \Atlas\Nexus\Services\Threads\Hooks\ThreadSummaryHook::class,
    \Atlas\Nexus\Services\Threads\Hooks\ThreadMemoryHook::class,
];

return [
    'database' => [
        'connection' => env('ATLAS_NEXUS_DATABASE_CONNECTION'),
        'tables' => [
            'ai_assistants' => 'ai_assistants',
            'ai_assistant_prompts' => 'ai_assistant_prompts',
            'ai_threads' => 'ai_threads',
            'ai_messages' => 'ai_messages',
            'ai_message_tools' => 'ai_message_tools',
            'ai_memories' => 'ai_memories',
        ],
    ],

    'queue' => env('ATLAS_NEXUS_QUEUE'),

    'thread_summary' => [
        'minimum_messages' => (int) env('ATLAS_NEXUS_THREAD_SUMMARY_MIN_MESSAGES', 2),
        'message_interval' => (int) env('ATLAS_NEXUS_THREAD_SUMMARY_MESSAGE_INTERVAL', 10),
    ],

    'memory' => [
        'default_importance' => 3,
        'decay_days' => 30,
        'pending_message_count' => 4,
    ],

    'threads' => [
        'snapshot_prompts' => true,
    ],

    'defaults' => [
        'agents' => $defaultAgents,
        'prompt_attributes' => $defaultPromptAttributes,
    ],

    'agents' => $defaultAgents,

    'prompt_attributes' => $defaultPromptAttributes,

    'variables' => [
        ...$defaultPromptAttributes,
        // \App\Nexus\Prompts\Variables\CustomPromptVariable::class,
    ],

    'thread_hooks' => [
        ...$defaultThreadHooks,
        // \App\Nexus\Threads\Hooks\CustomHook::class,
    ],
];
