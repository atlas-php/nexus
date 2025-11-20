<?php

declare(strict_types=1);

return [
    'default_pipeline' => env('NEXUS_DEFAULT_PIPELINE', 'default'),

    'pipelines' => [
        'default' => [
            'description' => 'Sandbox pipeline for testing Prism-backed Nexus flows.',
            'provider' => env('PRISM_DEFAULT_PROVIDER', 'openai'),
            'model' => env('PRISM_DEFAULT_MODEL', 'gpt-4o-mini'),
            'system_prompt' => env('PRISM_SYSTEM_PROMPT', 'You are the Atlas Nexus sandbox assistant.'),
            'provider_config' => [
                'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
                'api_key' => env('OPENAI_API_KEY'),
                'organization' => env('OPENAI_ORGANIZATION'),
                'project' => env('OPENAI_PROJECT'),
            ],
        ],
    ],
];
