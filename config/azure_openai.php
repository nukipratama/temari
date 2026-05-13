<?php

declare(strict_types=1);

/*
 * Raw values only — no closures. Closures break `php artisan config:cache`
 * in production. The `enabled` check is inlined in AppServiceProvider.
 *
 * Set AZURE_OPENAI_URI to the full chat-completions URL (deployment +
 * api-version inline). Leaving both vars blank disables LLM narration
 * entirely — Briefing falls back to the rule-based implementation
 * silently (no degraded chip shown).
 */

$uri = (string) env('AZURE_OPENAI_URI', '');
preg_match('#/deployments/([^/]+)/#', $uri, $matches);

return [
    'uri' => $uri,
    'api_key' => (string) env('AZURE_OPENAI_API_KEY', ''),
    'timeout' => (int) env('AZURE_OPENAI_TIMEOUT', 8),
    'max_tokens' => 400, // hardcoded cap — see plan §6.1 for rationale
    'deployment' => $matches[1] ?? '',
];
