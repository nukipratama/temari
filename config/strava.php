<?php

declare(strict_types=1);

return [
    // Reads of the daily pool held back for Live priority; background reads may
    // spend everything above it. See docs/decisions/backfill-borrows-the-live-reserve.md.
    'live_read_floor' => (int) env('STRAVA_LIVE_READ_FLOOR', 400),
];
