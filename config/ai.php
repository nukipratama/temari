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
    // clicks Reread across multiple analyses in rapid succession.
    'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 8),

    // Per-user ceiling (sliding minute) on "ask about this run". Lower than the
    // trigger limit above: every accepted question is a real tool-calling agent
    // run, where a trigger usually collapses to a no-op on an already-Done row.
    // Still a rate limit, not a cost cap — app-wide spend stays the
    // azure_openai.daily_cost_ceiling's job.
    'run_question_rate_limit_per_minute' => (int) env('AI_RUN_QUESTION_RATE_LIMIT_PER_MINUTE', 4),

    // Activities ingested with `start_date_local` more than this many hours
    // ago are treated as backfill — their auto-cascade gets staggered so a
    // Strava connect+backfill doesn't burst hundreds of LLM calls at once.
    'backfill_threshold_hours' => (int) env('AI_BACKFILL_THRESHOLD_HOURS', 24),

    // Delay between successive backfilled cascades per user. 6 min default →
    // 100 backfilled activities span ~10 hours of staggered LLM work.
    'backfill_stagger_seconds' => (int) env('AI_BACKFILL_STAGGER_SECONDS', 360),

    // An activity/week/month older than this gets the deterministic
    // rule-based filler instead of a real LLM call — nobody's checking back
    // on narration for a run from last season, and it keeps backfill depth
    // bounded. 12 weeks is a training block; beyond it a narrated run is
    // history, not context. See docs/decisions/twelve-week-narration-cutoff.md.
    'backfill_max_age_days' => (int) env('AI_BACKFILL_MAX_AGE_DAYS', 84),

    // Local ceiling on outbound Azure OpenAI calls, checked before every
    // request so concurrent workers self-throttle instead of firehosing
    // Azure and eating 429s. No documented Azure quota exists to size this
    // against — start conservative, raise if logs show headroom.
    'azure_calls_per_minute' => (int) env('AI_AZURE_CALLS_PER_MINUTE', 15),

    // How long a worker waits for a local throttle slot (above) before giving
    // up and spending a retry attempt. The `ai` queue's own Horizon supervisor
    // timeout is 300s, sized for a tool-calling narrator's several Azure round
    // trips — this must stay safely under that with generation room to spare.
    'azure_block_cap_seconds' => (int) env('AI_AZURE_BLOCK_CAP_SECONDS', 90),
];
