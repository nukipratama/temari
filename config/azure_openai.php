<?php

declare(strict_types=1);

// Raw values only — closures break `php artisan config:cache` in production.
// Empty AZURE_OPENAI_URI/API_KEY = LLM narration silently disabled (Briefing
// falls back to rule-based; no degraded chip shown).

$uri = (string) env('AZURE_OPENAI_URI', '');
preg_match('#/deployments/([^/]+)/#', $uri, $matches);

return [
    'uri' => $uri,
    'api_key' => (string) env('AZURE_OPENAI_API_KEY', ''),
    'timeout' => (int) env('AZURE_OPENAI_TIMEOUT', 8),
    'max_completion_tokens' => 400,
    'deployment' => $matches[1] ?? '',
];
