<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which tier of the shared per-client Strava read budget a call may spend from.
 *
 * `Live` may spend the whole pool; `Background` stops at the reserve floor
 * {@see \App\Services\Strava\StravaClient} holds back for freshly-finished runs.
 */
enum StravaReadPriority: string
{
    case Live = 'live';
    case Background = 'background';

    public function isLive(): bool
    {
        return $this === self::Live;
    }

    /**
     * Throttle key for the queue-side backoff circuit, so a stalled background
     * tier never releases live ingest jobs alongside it.
     */
    public function throttleKey(): string
    {
        return "strava-ingest:{$this->value}";
    }
}
