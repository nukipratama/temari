<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\SessionType;
use App\Models\ActivityDetail;

/**
 * Whether a run was *meant* to be hard (a race, or a tempo/threshold workout)
 * versus an easy/recovery effort. High HR, TRIMP, and aerobic decoupling are the
 * point of a quality session, so mood + narration must not read them as fatigue.
 *
 * Intent reads, in order: the Strava `workout_type` the runner tagged, the day's
 * settled planned session, a fast/slow rep structure in the laps, then the
 * HR-zone distribution (Z3-Z4 heavy = threshold work). Zone shape is used rather
 * than pace or pace-variability because a real tempo often carries an easy
 * warm-up/cool-down that inflates both and hides the hard block. See
 * `docs/decisions/session-intent-reads-the-plan.md`.
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

    public const string PLANNED = 'planned';

    public const string LAPS = 'laps';

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
    public static function forDetail(ActivityDetail $detail, ?SessionType $planned = null): array
    {
        $tagged = self::fromWorkoutType($detail->workout_type);
        if ($tagged !== null) {
            return ['intent' => $tagged, 'source' => self::TAGGED];
        }

        $plannedIntent = self::fromPlannedType($planned);
        if ($plannedIntent !== null) {
            return ['intent' => $plannedIntent, 'source' => self::PLANNED];
        }

        $summary = StreamSummary::fromArray($detail->streamSummary());
        if (IntervalDetector::detect(IntervalDetector::lapPaces($summary->laps() ?? [])) !== []) {
            return ['intent' => self::WORKOUT, 'source' => self::LAPS];
        }

        $easyDay = $planned === SessionType::Easy;
        if ($summary->zonePct() === [] && ! $easyDay) {
            return ['intent' => self::UNKNOWN, 'source' => self::NONE];
        }

        if (self::looksLikeThresholdWork($summary)) {
            return ['intent' => self::WORKOUT, 'source' => self::INFERRED];
        }

        return ['intent' => self::EASY, 'source' => $easyDay ? self::PLANNED : self::NONE];
    }

    public static function isIntendedHard(ActivityDetail $detail, ?SessionType $planned = null): bool
    {
        $intent = self::forDetail($detail, $planned)['intent'];

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

    private static function fromPlannedType(?SessionType $planned): ?string
    {
        return match ($planned) {
            SessionType::Tempo, SessionType::Interval => self::WORKOUT,
            SessionType::Race => self::RACE,
            SessionType::Long => self::LONG_RUN,
            default => null,
        };
    }

    private static function looksLikeThresholdWork(StreamSummary $summary): bool
    {
        $z4Share = (float) ($summary->zonePct()['Z4'] ?? 0);

        return $summary->hardZoneShare() >= self::HARD_SHARE_MIN
            || $z4Share >= self::THRESHOLD_SHARE_MIN;
    }
}
