<?php

return [
    'provider' => env('AI_PROVIDER', 'anthropic'),
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
        'timeout' => env('AI_TIMEOUT', 5),
        'max_tokens' => 500,
    ],
    'fallback_on_failure' => true,
    'cache_ttl_minutes' => 60,
];