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
            'ai_tool_runs' => 'ai_tool_runs',
            'ai_memories' => 'ai_memories',
        ],
    ],

    'queue' => env('ATLAS_NEXUS_QUEUE'),

    'assistants' => [
        \Atlas\Nexus\Assistants\GeneralAssistant::class,
        \Atlas\Nexus\Assistants\HumanAssistant::class,
        \Atlas\Nexus\Assistants\ThreadManagerAssistant::class,
    ],

    'variables' => [
        \Atlas\Nexus\Support\Prompts\Variables\ThreadPromptVariables::class,
        \Atlas\Nexus\Support\Prompts\Variables\UserPromptVariables::class,
        \Atlas\Nexus\Support\Prompts\Variables\MemoryPromptVariables::class,
        // \App\Nexus\Prompts\Variables\CustomPromptVariable::class,
    ],
];
