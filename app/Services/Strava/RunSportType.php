<?php

declare(strict_types=1);

namespace App\Services\Strava;

/**
 * Single source of truth for which Strava sport types this app treats as runs.
 * Shared by the poll-path discovery filter ({@see ActivityFetcher}) and the
 * webhook ingest choke point ({@see \App\Services\Run\Ingest\ActivityPipeline})
 * so the two can never drift apart.
 */
final class RunSportType
{
    /**
     * @var list<string>
     */
    private const array RUN_TYPES = ['Run', 'VirtualRun', 'TrailRun'];

    /**
     * Whether a Strava activity payload (summary or detail) is a run. Prefers the
     * newer `sport_type` field, falling back to the legacy `type`.
     *
     * @param  array<string, mixed>  $activity
     */
    public static function matches(array $activity): bool
    {
        $type = (string) ($activity['sport_type'] ?? $activity['type'] ?? '');

        return in_array($type, self::RUN_TYPES, strict: true);
    }

    /**
     * Whether the payload carries a sport type that is explicitly NOT a run
     * (Ride / Walk / Swim / ...). A payload with no sport type at all returns
     * false: the webhook ingest guard only deletes an activity when Strava has
     * positively identified it as a non-run, never on an unexpectedly-shaped
     * payload.
     *
     * @param  array<string, mixed>  $activity
     */
    public static function isExplicitlyNotRun(array $activity): bool
    {
        $type = $activity['sport_type'] ?? $activity['type'] ?? null;

        return is_string($type) && $type !== '' && ! self::matches($activity);
    }
}
