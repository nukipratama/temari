<?php

declare(strict_types=1);

// Raw values only — closures break `php artisan config:cache` in production.
// Empty AZURE_OPENAI_URI/API_KEY = LLM narration silently disabled (Briefing
// falls back to rule-based; no degraded chip shown).

/**
 * Extract the Azure deployment slug from a full chat-completions URI.
 * Local helper at file-eval time only; the returned array stays closure-free.
 */
$deploymentFromUri = static function (string $uri): string {
    preg_match('#/deployments/([^/]+)/#', $uri, $matches);

    return $matches[1] ?? '';
};

$uri = (string) env('AZURE_OPENAI_URI', '');

/**
 * Build a per-narrator routing profile from `AZURE_OPENAI_<KIND>_*` env vars.
 * Empty uri/api_key signals "fall back to the default profile" at resolve time.
 */
$narratorProfile = static function (string $envKind) use ($deploymentFromUri): array {
    $kindUri = (string) env("AZURE_OPENAI_{$envKind}_URI", '');

    return [
        'uri' => $kindUri,
        'api_key' => (string) env("AZURE_OPENAI_{$envKind}_API_KEY", ''),
        'deployment' => $deploymentFromUri($kindUri),
    ];
};

return [
    'uri' => $uri,
    'api_key' => (string) env('AZURE_OPENAI_API_KEY', ''),
    'timeout' => (int) env('AZURE_OPENAI_TIMEOUT', 15),
    'max_completion_tokens' => (int) env('AZURE_OPENAI_MAX_COMPLETION_TOKENS', 700),
    'deployment' => $deploymentFromUri($uri),

    // Per-narrator Azure routing keyed by the $kind passed to caller->call().
    // Any profile with an empty uri OR api_key falls back to the default profile
    // above. Env key is the kind upper-snake-cased, e.g. AZURE_OPENAI_BRIEFING_URI.
    'narrators' => [
        'briefing' => $narratorProfile('BRIEFING'),
        'run_insight' => $narratorProfile('RUN_INSIGHT'),
        'post_run_speech' => $narratorProfile('POST_RUN_SPEECH'),
        'weekly_recap' => $narratorProfile('WEEKLY_RECAP'),
        'monthly_recap' => $narratorProfile('MONTHLY_RECAP'),
        'trend_caption' => $narratorProfile('TREND_CAPTION'),
    ],

    // USD list prices per 1M tokens, keyed by deployment slug. Seeded with Azure
    // retail list prices; ai:refresh-azure-prices refreshes these weekly into the
    // cache under price_cache_key (this map is the fallback when the cache is cold).
    'prices' => [
        'gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00, 'currency' => 'USD'],
        'gpt-4o-mini' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60, 'currency' => 'USD'],
    ],

    // Default currency for cost roll-ups when no priced deployment is present.
    'currency' => 'USD',

    // Nullable USD/day spend ceiling. null = no ceiling (auto-dispatch never
    // budget-gated). When set, AnalysisService skips dispatch once today's spend
    // exceeds it.
    'daily_cost_ceiling' => env('AZURE_OPENAI_DAILY_COST_CEILING') !== null
        ? (float) env('AZURE_OPENAI_DAILY_COST_CEILING')
        : null,

    // Cache key holding the refreshed retail price map.
    'price_cache_key' => 'azure_openai.prices.refreshed',
];
