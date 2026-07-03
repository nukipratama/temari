<?php

declare(strict_types=1);

return [
    'auto_dispatch' => filter_var(env('AI_AUTO_DISPATCH', true), FILTER_VALIDATE_BOOLEAN),

    'queue' => (string) env('AI_QUEUE', 'default'),

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
