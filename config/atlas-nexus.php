<?php

declare(strict_types=1);

return [
    'responses' => [
        'queue' => env('ATLAS_NEXUS_RESPONSES_QUEUE'),
    ],

    'prompts' => [
        'freeze_thread' => true,
        'variables' => [
            \Atlas\Nexus\Support\Prompts\Variables\UserPromptVariables::class,
            // \App\Nexus\Prompts\Variables\CustomPromptVariable::class,
        ],
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
                'content_limit' => 8000,
                'summary_model' => null,
                'allowed_domains' => null,
                'parse_mode' => 'markdown', // markdown | text_only | null
            ],
            'thread_manager' => [
                'model' => null,
            ],
        ],
    ],

    'seeders' => [
        \Atlas\Nexus\Services\Seeders\WebSearchAssistantSeeder::class,
        \Atlas\Nexus\Services\Seeders\ThreadManagerAssistantSeeder::class,
    ],

    'tables' => [
        'ai_assistants' => 'ai_assistants',
        'ai_prompts' => 'ai_prompts',
        'ai_threads' => 'ai_threads',
        'ai_messages' => 'ai_messages',
        'ai_tool_runs' => 'ai_tool_runs',
        'ai_memories' => 'ai_memories',
    ],

    'database' => [
        'connection' => env('ATLAS_NEXUS_DATABASE_CONNECTION'),
    ],
];
