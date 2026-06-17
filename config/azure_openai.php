<?php

declare(strict_types=1);

// Raw env values only — closures break `php artisan config:cache`.
// Empty AZURE_OPENAI_URI/DEPLOYMENT = LLM narration silently disabled (Briefing
// falls back to rule-based; no degraded chip shown).

return [
    // Azure resource endpoint (host only), e.g. https://my-res.openai.azure.com.
    // AzureOpenAIClient appends /openai/v1; the deployment is sent as the request
    // body `model`, not in the URL path.
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

    // Manual per-1M-token USD rates keyed by DEPLOYMENT name (the value recorded
    // in ai_token_usages.model), for the /ai-usage cost estimate. Azure's retail
    // price catalog (Foundry Models) uses cryptic, region/tier-specific meter
    // names that can't be matched reliably, so these are maintained by hand from
    // https://azure.microsoft.com/pricing/details/cognitive-services/openai-service/.
    // Values are Global Standard; map the deployment to its model's published rate.
    'prices' => [
        'nuki-5.2' => ['input_per_1m' => 1.75, 'output_per_1m' => 14.00],       // gpt-5.2
        'nuki-5.4-mini' => ['input_per_1m' => 0.75, 'output_per_1m' => 4.50],   // gpt-5.4-mini
    ],

    // Nullable USD/day spend ceiling. null = no ceiling (auto-dispatch never
    // budget-gated). When set, AnalysisService skips dispatch once today's spend
    // exceeds it.
    'daily_cost_ceiling' => env('AZURE_OPENAI_DAILY_COST_CEILING') !== null
        ? (float) env('AZURE_OPENAI_DAILY_COST_CEILING')
        : null,
];
