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
    // A Guzzle timeout drops the connection but Azure still bills the completion
    // it generated, and the token meter can't record a response it never got, so
    // this must clear the slowest narration. It is also how far past its
    // wall-clock deadline (`ai.agent.deadline_seconds`) a run can overshoot, so
    // their sum must stay under the Horizon `ai` supervisor's timeout.
    'timeout' => (int) env('AZURE_OPENAI_TIMEOUT', 90),
    'max_completion_tokens' => (int) env('AZURE_OPENAI_MAX_COMPLETION_TOKENS', 700),

    // Primary/default deployment (model) name — the fallback for every narrator.
    'deployment' => (string) env('AZURE_OPENAI_DEPLOYMENT', ''),

    // Per-narrator model override (deployment name only; host + key are shared).
    // Each defaults to the general AZURE_OPENAI_DEPLOYMENT, so an unset kind just
    // uses the primary model.
    'narrators' => [
        'briefing_mascot_voice' => (string) env('AZURE_OPENAI_BRIEFING_MASCOT_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'run_insight' => (string) env('AZURE_OPENAI_RUN_INSIGHT_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'post_run_speech' => (string) env('AZURE_OPENAI_POST_RUN_SPEECH_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'weekly_recap' => (string) env('AZURE_OPENAI_WEEKLY_RECAP_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'monthly_recap' => (string) env('AZURE_OPENAI_MONTHLY_RECAP_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'profile_voice' => (string) env('AZURE_OPENAI_PROFILE_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'card_flavor' => (string) env('AZURE_OPENAI_CARD_FLAVOR_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'run_question' => (string) env('AZURE_OPENAI_RUN_QUESTION_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'trend_read' => (string) env('AZURE_OPENAI_TREND_READ_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'plan_day_voice' => (string) env('AZURE_OPENAI_PLAN_DAY_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'plan_clamp_voice' => (string) env('AZURE_OPENAI_PLAN_CLAMP_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'plan_week_voice' => (string) env('AZURE_OPENAI_PLAN_WEEK_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
        'plan_season_voice' => (string) env('AZURE_OPENAI_PLAN_SEASON_VOICE_DEPLOYMENT', env('AZURE_OPENAI_DEPLOYMENT')),
    ],

    // Manual per-1M-token USD rates keyed by DEPLOYMENT name (the value recorded
    // in ai_token_usages.model), for the /ai-usage cost estimate. Azure's retail
    // price catalog (Foundry Models) uses cryptic, region/tier-specific meter
    // names that can't be matched reliably, so these are maintained by hand from
    // https://azure.microsoft.com/pricing/details/cognitive-services/openai-service/.
    // Values are Global Standard; map the deployment to its model's published rate.
    //
    // 'cached_input_per_1m' prices the slice of input the provider served from its
    // prompt cache, which both models bill at a tenth of their input rate. Omit it
    // for a deployment and its cached input bills as ordinary input, so a missing
    // entry only ever overstates the estimate, never understates it.
    // Measured on prod before these were filled in: 43-62% of input on multi-step
    // calls was already arriving cached, so the estimate was overstating by a lot.
    'prices' => [
        // gpt-5.2
        'nuki-5.2' => ['input_per_1m' => 1.75, 'cached_input_per_1m' => 0.17, 'output_per_1m' => 14.00],
        // gpt-5.4-mini
        'nuki-5.4-mini' => ['input_per_1m' => 0.75, 'cached_input_per_1m' => 0.075, 'output_per_1m' => 4.50],
    ],

    // USD/day spend ceiling for a SINGLE athlete, on by default. Once their own
    // estimated spend today exceeds it, AnalysisService stops dispatching to the
    // LLM for them and serves their narration from the deterministic rule-based
    // filler until midnight resets the daily cost. Everyone else is unaffected.
    //
    // It binds first for a heavy athlete on an ordinary day, and keeps one
    // athlete from spending everybody else's budget.
    'daily_cost_ceiling_per_user' => env('AZURE_OPENAI_DAILY_COST_CEILING_PER_USER') !== null
        ? (float) env('AZURE_OPENAI_DAILY_COST_CEILING_PER_USER')
        : 1.00,

    // USD/day cap on what re-narration replays (AnalysisOrigin::Replay) may
    // spend across the whole app. A replay is an operator's QA tool, not an
    // athlete's narration, so it counts against this and the app-wide total only
    // — never against the athlete's own daily slice, which their real narration
    // needs.
    'replay_daily_cap' => env('AZURE_OPENAI_REPLAY_DAILY_CAP') !== null
        ? (float) env('AZURE_OPENAI_REPLAY_DAILY_CAP')
        : 0.50,

    // USD/day ceiling on the WHOLE app's spend, sitting above the per-athlete
    // one. Past it, every athlete's pending narration is served from the same
    // rule-based filler until midnight, so the bill is bounded absolutely rather
    // than by a figure that grows with the athlete count. Null disables it.
    'daily_cost_ceiling_total' => env('AZURE_OPENAI_DAILY_COST_CEILING_TOTAL') !== null
        ? (float) env('AZURE_OPENAI_DAILY_COST_CEILING_TOTAL')
        : 5.00,
];
