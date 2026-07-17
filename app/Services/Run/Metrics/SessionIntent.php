<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\ActivityDetail;

/**
 * Whether a run was *meant* to be hard (a race, or a tempo/threshold workout)
 * versus an easy/recovery effort. High HR, TRIMP, and aerobic decoupling are the
 * point of a quality session, so mood + narration must not read them as fatigue.
 *
 * Intent anchors on the Strava `workout_type` the runner tagged; when untagged it
 * is inferred from the HR-zone distribution (Z3-Z4 heavy = threshold work). Zone
 * shape is used rather than pace or pace-variability because a real tempo often
 * carries an easy warm-up/cool-down that inflates both and hides the hard block.
 */
final class SessionIntent
{
    public const string RACE = 'race';

    public const string WORKOUT = 'workout';

    public const string LONG_RUN = 'long_run';

    public const string EASY = 'easy';

    public const string UNKNOWN = 'unknown';

    public const string TAGGED = 'tagged';

    public const string INFERRED = 'inferred';

    public const string NONE = 'none';

    /** Strava run workout_type codes (0/null = default/none). */
    private const int STRAVA_RACE = 1;

    private const int STRAVA_LONG_RUN = 2;

    private const int STRAVA_WORKOUT = 3;

    /** Z3+Z4+Z5 share at/above this reads as a genuine hard session. */
    private const float HARD_SHARE_MIN = 80.0;

    /** Sustained threshold (Z4) share at/above this reads as tempo/threshold work. */
    private const float THRESHOLD_SHARE_MIN = 20.0;

    /**
     * @return array{intent: string, source: string}
     */
    public static function forDetail(ActivityDetail $detail): array
    {
        $tagged = self::fromWorkoutType($detail->workout_type);
        if ($tagged !== null) {
            return ['intent' => $tagged, 'source' => self::TAGGED];
        }

        $summary = $detail->streamSummary();
        if (StreamSummary::zonePct($summary) === []) {
            return ['intent' => self::UNKNOWN, 'source' => self::NONE];
        }

        if (self::looksLikeThresholdWork($summary)) {
            return ['intent' => self::WORKOUT, 'source' => self::INFERRED];
        }

        return ['intent' => self::EASY, 'source' => self::NONE];
    }

    public static function isIntendedHard(ActivityDetail $detail): bool
    {
        $intent = self::forDetail($detail)['intent'];

        return $intent === self::RACE || $intent === self::WORKOUT;
    }

    private static function fromWorkoutType(?int $workoutType): ?string
    {
        return match ($workoutType) {
            self::STRAVA_RACE => self::RACE,
            self::STRAVA_WORKOUT => self::WORKOUT,
            self::STRAVA_LONG_RUN => self::LONG_RUN,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function looksLikeThresholdWork(array $summary): bool
    {
        $z4Share = (float) (StreamSummary::zonePct($summary)['Z4'] ?? 0);

        return StreamSummary::hardZoneShare($summary) >= self::HARD_SHARE_MIN
            || $z4Share >= self::THRESHOLD_SHARE_MIN;
    }
}
