<?php

declare(strict_types=1);

// Raw env values only — closures break `php artisan config:cache`.
// Empty AZURE_OPENAI_URI/DEPLOYMENT = LLM narration silently disabled (Briefing
// falls back to rule-based; no degraded chip shown).

return [
    // Azure resource endpoint (host only), e.g. https://my-res.openai.azure.com.
    // AzureOpenAIClient appends /openai/deployments/{deployment}/chat/completions.
    'uri' => (string) env('AZURE_OPENAI_URI', ''),
    'api_key' => (string) env('AZURE_OPENAI_API_KEY', ''),
    'timeout' => (int) env('AZURE_OPENAI_TIMEOUT', 15),
    'max_completion_tokens' => (int) env('AZURE_OPENAI_MAX_COMPLETION_TOKENS', 700),

    // Primary/default deployment (model) name — the fallback for every narrator.
    'deployment' => (string) env('AZURE_OPENAI_DEPLOYMENT', ''),

    // Per-narrator model override (deployment name only; host + key are shared).
    // Each defaults to the general AZURE_OPENAI_DEPLOYMENT, so an unset kind just
    // uses the primary model.
    'narrators' => [
        'briefing' => (string) env('AZURE_OPENAI_BRIEFING_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'briefing_mascot_voice' => (string) env('AZURE_OPENAI_BRIEFING_MASCOT_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'briefing_featured_kartu_voice' => (string) env('AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'daily_greeting' => (string) env('AZURE_OPENAI_DAILY_GREETING_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'run_insight' => (string) env('AZURE_OPENAI_RUN_INSIGHT_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'post_run_speech' => (string) env('AZURE_OPENAI_POST_RUN_SPEECH_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'pr_context' => (string) env('AZURE_OPENAI_PR_CONTEXT_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'weekly_recap' => (string) env('AZURE_OPENAI_WEEKLY_RECAP_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'monthly_recap' => (string) env('AZURE_OPENAI_MONTHLY_RECAP_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'trend_caption' => (string) env('AZURE_OPENAI_TREND_CAPTION_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'persona_summary' => (string) env('AZURE_OPENAI_PERSONA_SUMMARY_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'aku_profile_voice' => (string) env('AZURE_OPENAI_AKU_PROFILE_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'card_flavor' => (string) env('AZURE_OPENAI_CARD_FLAVOR_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
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
