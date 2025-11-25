<?php

declare(strict_types=1);

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

    'assistants' => [
        \Atlas\Nexus\Assistants\GeneralAssistant::class,
        \Atlas\Nexus\Assistants\HumanAssistant::class,
        \Atlas\Nexus\Assistants\ThreadManagerAssistant::class,
        \Atlas\Nexus\Assistants\MemoryAssistant::class,
        // Additional consumer-defined assistants go here.
    ],

    'context_prompt' => \Atlas\Nexus\Support\Prompts\ContextPrompt::class,

    'variables' => [
        \Atlas\Nexus\Support\Prompts\Variables\ThreadPromptVariables::class,
        \Atlas\Nexus\Support\Prompts\Variables\UserPromptVariables::class,
        \Atlas\Nexus\Support\Prompts\Variables\MemoryPromptVariables::class,
        // \App\Nexus\Prompts\Variables\CustomPromptVariable::class,
    ],
];
