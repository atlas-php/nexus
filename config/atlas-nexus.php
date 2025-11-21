<?php

declare(strict_types=1);

return [
    'default_pipeline' => 'default',

    'pipelines' => [
        'default' => [
            'description' => 'Placeholder default pipeline until personas define workflows.',
        ],
    ],

    'responses' => [
        'queue' => env('ATLAS_NEXUS_RESPONSES_QUEUE'),
    ],

    'tools' => [
        'registry' => [
            'memory' => \Atlas\Nexus\Integrations\Prism\Tools\MemoryTool::class,
            'web_search' => \Atlas\Nexus\Integrations\Prism\Tools\WebSearchTool::class,
            'thread_manager' => \Atlas\Nexus\Integrations\Prism\Tools\ThreadManagerTool::class,
            // 'custom_tool' => \App\Tools\CustomTool::class,
        ],
        'options' => [
            'web_search' => [
                'content_limit' => env('ATLAS_NEXUS_WEB_SUMMARY_CONTENT_LIMIT', 8000),
                'summary_model' => env('ATLAS_NEXUS_WEB_SUMMARY_MODEL'),
            ],
            'thread_manager' => [
                'model' => env('ATLAS_NEXUS_THREAD_MANAGER_MODEL'),
            ],
        ],
    ],

    'seeders' => [
        \Atlas\Nexus\Services\Seeders\WebSearchAssistantSeeder::class,
        \Atlas\Nexus\Services\Seeders\ThreadManagerAssistantSeeder::class,
    ],

    'tables' => [
        'ai_assistants' => env('ATLAS_NEXUS_TABLE_AI_ASSISTANTS', 'ai_assistants'),
        'ai_prompts' => env('ATLAS_NEXUS_TABLE_AI_PROMPTS', 'ai_prompts'),
        'ai_threads' => env('ATLAS_NEXUS_TABLE_AI_THREADS', 'ai_threads'),
        'ai_messages' => env('ATLAS_NEXUS_TABLE_AI_MESSAGES', 'ai_messages'),
        'ai_tool_runs' => env('ATLAS_NEXUS_TABLE_AI_TOOL_RUNS', 'ai_tool_runs'),
        'ai_memories' => env('ATLAS_NEXUS_TABLE_AI_MEMORIES', 'ai_memories'),
    ],

    'database' => [
        'connection' => env('ATLAS_NEXUS_DATABASE_CONNECTION'),
    ],
];
