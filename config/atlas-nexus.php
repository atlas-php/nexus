<?php

declare(strict_types=1);

return [
    'default_pipeline' => 'default',

    'pipelines' => [
        'default' => [
            'description' => 'Placeholder default pipeline until personas define workflows.',
        ],
    ],

    'tables' => [
        'ai_assistants' => env('ATLAS_NEXUS_TABLE_AI_ASSISTANTS', 'ai_assistants'),
        'ai_prompts' => env('ATLAS_NEXUS_TABLE_AI_PROMPTS', 'ai_prompts'),
        'ai_threads' => env('ATLAS_NEXUS_TABLE_AI_THREADS', 'ai_threads'),
        'ai_messages' => env('ATLAS_NEXUS_TABLE_AI_MESSAGES', 'ai_messages'),
        'ai_tools' => env('ATLAS_NEXUS_TABLE_AI_TOOLS', 'ai_tools'),
        'ai_assistant_tool' => env('ATLAS_NEXUS_TABLE_AI_ASSISTANT_TOOL', 'ai_assistant_tool'),
        'ai_tool_runs' => env('ATLAS_NEXUS_TABLE_AI_TOOL_RUNS', 'ai_tool_runs'),
        'ai_memories' => env('ATLAS_NEXUS_TABLE_AI_MEMORIES', 'ai_memories'),
    ],

    'database' => [
        'connection' => env('ATLAS_NEXUS_DATABASE_CONNECTION'),
    ],
];
