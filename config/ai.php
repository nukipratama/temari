<?php

declare(strict_types=1);

return [
    'auto_dispatch' => filter_var(env('AI_AUTO_DISPATCH', true), FILTER_VALIDATE_BOOLEAN),

    // Per-block ceiling on an agent run. The daily cost ceiling only gates
    // dispatch, so this is what stops one runaway tool loop from spending the
    // day's budget inside a single job. max_steps is sized for the widest
    // toolbox; a narrator with a small one tightens it via
    // ChatCallOptions::$maxSteps, since every turn re-bills the whole prefix.
    'agent' => [
        'max_steps' => (int) env('AI_AGENT_MAX_STEPS', 8),
        'max_tokens' => (int) env('AI_AGENT_MAX_TOKENS', 30000),
    ],

    // Per-user trigger ceiling (sliding minute). Catches the case where a user
    // clicks Analisis ulang across multiple analyses in rapid succession.
    'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 8),

    // Activities ingested with `start_date_local` more than this many hours
    // ago are treated as backfill — their auto-cascade gets staggered so a
    // Strava connect+backfill doesn't burst hundreds of LLM calls at once.
    'backfill_threshold_hours' => (int) env('AI_BACKFILL_THRESHOLD_HOURS', 24),

    // Delay between successive backfilled cascades per user. 6 min default →
    // 100 backfilled activities span ~10 hours of staggered LLM work.
    'backfill_stagger_seconds' => (int) env('AI_BACKFILL_STAGGER_SECONDS', 360),
];
