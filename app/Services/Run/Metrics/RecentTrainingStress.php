<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Carbon;

final readonly class RecentTrainingStress
{
    private const int WINDOW_DAYS = 7;

    private const int LONG_RUN_MINUTES = 90;

    private const int RELATIVE_LONG_MINUTES = 60;

    private const float RELATIVE_LONG_MULTIPLIER = 1.5;

    private const int THRESHOLD_EFFORT_MINUTES = 10;

    private const float THRESHOLD_PACE_TOLERANCE = 1.05;

    public function __construct(
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $paceCalculator,
    ) {
    }

    /**
     * @return array{
     *     sessions: list<array{
     *         date: string,
     *         hours_ago: int,
     *         hours_since_end: int,
     *         distance_km: float|null,
     *         duration_minutes: int|null,
     *         personal_typical_duration_minutes: float|null,
     *         trimp: float|null,
     *         threshold_minutes: float|null,
     *         non_easy_minutes: float|null,
     *         threshold_pace_sec_per_km: int|null,
     *         lap_threshold_minutes: float|null,
     *         gap_pace_sec_per_km: int|null,
     *         gap_threshold_minutes: float|null,
     *         has_hr_evidence: bool,
     *         has_laps: bool,
     *         has_gap: bool,
     *         demanding: bool,
     *         reasons: list<string>
     *     }>,
     *     last_demanding_hours: int|null,
     *     demanding_within_24h: int,
     *     demanding_within_48h: int
     * }
     */
    public function forUser(User $user, Carbon $asOf, int $windowDays = self::WINDOW_DAYS): array
    {
        $today = $asOf->copy()->startOfDay();
        $now = $today->isSameDay(Carbon::now()) ? Carbon::now() : $today->copy()->endOfDay();
        $start = $today->copy()->subDays($windowDays - 1)->startOfDay();

        $activities = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $user->id)
            ->whereBetween('activity_details.start_date_local', [$start, $now])
            ->orderBy('activity_details.start_date_local')
            ->get([
                'activity_details.id',
                'activity_details.start_date_local',
                'activity_details.distance',
                'activity_details.elapsed_time',
                'activity_details.trimp_edwards',
                'activity_details.stream_summary',
                'activity_details.has_heartrate',
            ]);
        $needsThresholdPace = $activities->contains(function (ActivityDetail $detail): bool {
            $summary = StreamSummary::fromArray($detail->streamSummary());

            return $summary->zoneMinutes() === null
                && (self::paceSecondsPerKm($summary->gapPace()) !== null
                    || self::hasLapPaceEvidence($summary->laps()));
        });
        $thresholdPace = $needsThresholdPace
            ? ($this->paceCalculator->fromVdotResult(
                $this->vdotEstimator->estimate($user, $asOf),
            )['threshold'] ?? null)
            : null;

        $sessions = $activities->map(function (ActivityDetail $detail) use ($activities, $now, $thresholdPace): array {
            $summary = StreamSummary::fromArray($detail->streamSummary());
            $zoneMinutes = $summary->zoneMinutes();
            $thresholdMinutes = $zoneMinutes === null
                ? null
                : (float) ($zoneMinutes['Z4'] ?? 0) + (float) ($zoneMinutes['Z5'] ?? 0);
            $durationSeconds = is_numeric($detail->elapsed_time) && $detail->elapsed_time > 0
                ? (int) $detail->elapsed_time
                : null;
            $durationMinutes = $durationSeconds === null ? null : (int) ceil($durationSeconds / 60);
            $typicalDuration = self::median($activities
                ->filter(fn (ActivityDetail $candidate): bool => Carbon::parse($candidate->start_date_local)->lessThan(Carbon::parse($detail->start_date_local)))
                ->map(fn (ActivityDetail $candidate): ?float => is_numeric($candidate->elapsed_time) && $candidate->elapsed_time > 0
                    ? (float) $candidate->elapsed_time
                    : null)
                ->filter(fn (?float $seconds): bool => $seconds !== null)
                ->all());
            $lapThresholdMinutes = $zoneMinutes === null
                ? self::lapThresholdMinutes($summary->laps(), $thresholdPace)
                : null;
            $gapPace = self::paceSecondsPerKm($summary->gapPace());
            $gapThresholdMinutes = $zoneMinutes === null
                && $thresholdPace !== null
                && $gapPace !== null
                && $durationSeconds !== null
                && $gapPace <= $thresholdPace * self::THRESHOLD_PACE_TOLERANCE
                ? $durationSeconds / 60
                : null;
            $startAt = Carbon::parse($detail->start_date_local);
            $endAt = $durationSeconds === null ? $startAt : $startAt->copy()->addSeconds($durationSeconds);
            $hoursAgo = max(0, (int) $startAt->diffInHours($now, absolute: true));
            $hoursSinceEnd = max(0, (int) $endAt->diffInHours($now, absolute: false));
            $reasons = [];

            if ($durationSeconds !== null && $durationSeconds >= self::LONG_RUN_MINUTES * 60) {
                $reasons[] = 'long_duration';
            } elseif ($durationSeconds !== null
                && $durationSeconds >= self::RELATIVE_LONG_MINUTES * 60
                && $typicalDuration !== null
                && $durationSeconds >= $typicalDuration * self::RELATIVE_LONG_MULTIPLIER) {
                $reasons[] = 'relative_long_duration';
            }
            if ($thresholdMinutes !== null && $thresholdMinutes >= self::THRESHOLD_EFFORT_MINUTES) {
                $reasons[] = 'sustained_threshold_effort';
            }
            if ($lapThresholdMinutes !== null && $lapThresholdMinutes >= self::THRESHOLD_EFFORT_MINUTES) {
                $reasons[] = 'sustained_threshold_lap_effort';
            }
            if ($gapThresholdMinutes !== null && $gapThresholdMinutes >= self::THRESHOLD_EFFORT_MINUTES) {
                $reasons[] = 'sustained_threshold_gap_effort';
            }

            return [
                'date' => $startAt->toDateString(),
                'hours_ago' => $hoursAgo,
                'hours_since_end' => $hoursSinceEnd,
                'distance_km' => $detail->distance === null ? null : round($detail->distance / 1000, 1),
                'duration_minutes' => $durationMinutes,
                'personal_typical_duration_minutes' => $typicalDuration === null ? null : round($typicalDuration / 60, 1),
                'trimp' => $detail->trimp_edwards,
                'threshold_minutes' => $thresholdMinutes === null ? null : round($thresholdMinutes, 1),
                'non_easy_minutes' => $zoneMinutes === null ? null : round((float) ($zoneMinutes['Z3'] ?? 0) + $thresholdMinutes, 1),
                'threshold_pace_sec_per_km' => $thresholdPace,
                'lap_threshold_minutes' => $lapThresholdMinutes === null ? null : round($lapThresholdMinutes, 1),
                'gap_pace_sec_per_km' => $gapPace,
                'gap_threshold_minutes' => $gapThresholdMinutes === null ? null : round($gapThresholdMinutes, 1),
                'has_hr_evidence' => $zoneMinutes !== null,
                'has_laps' => $summary->laps() !== null,
                'has_gap' => $summary->gapPace() !== null,
                'demanding' => $reasons !== [],
                'reasons' => $reasons,
            ];
        })->values()->all();

        $demanding = array_values(array_filter($sessions, static fn (array $session): bool => $session['demanding']));
        $lastDemandingHours = $demanding === []
            ? null
            : min(array_column($demanding, 'hours_since_end'));

        return [
            'sessions' => array_values($sessions),
            'last_demanding_hours' => $lastDemandingHours,
            'demanding_within_24h' => count(array_filter($demanding, static fn (array $session): bool => $session['hours_since_end'] < 24)),
            'demanding_within_48h' => count(array_filter($demanding, static fn (array $session): bool => $session['hours_since_end'] < 48)),
        ];
    }

    /** @param array<int, array<string, mixed>>|null $laps */
    private static function lapThresholdMinutes(?array $laps, ?int $thresholdPace): ?float
    {
        if ($laps === null || $thresholdPace === null) {
            return null;
        }

        $seconds = 0.0;
        foreach ($laps as $lap) {
            $elapsed = is_numeric($lap['elapsed_sec'] ?? null) ? (float) $lap['elapsed_sec'] : null;
            $pace = self::paceSecondsPerKm(is_string($lap['pace'] ?? null) ? $lap['pace'] : null);
            if ($pace === null && $elapsed !== null && $elapsed > 0 && is_numeric($lap['distance_m'] ?? null)) {
                $distance = (float) $lap['distance_m'];
                $pace = $distance > 0 ? $elapsed * 1000 / $distance : null;
            }
            if ($elapsed !== null && $elapsed > 0 && $pace !== null && $pace <= $thresholdPace * self::THRESHOLD_PACE_TOLERANCE) {
                $seconds += $elapsed;
            }
        }

        return $seconds === 0.0 ? null : $seconds / 60;
    }

    private static function paceSecondsPerKm(?string $pace): ?int
    {
        if ($pace === null || preg_match('/^(\d{1,2}):([0-5]\d)$/', trim($pace), $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    /** @param array<int, array<string, mixed>>|null $laps */
    private static function hasLapPaceEvidence(?array $laps): bool
    {
        foreach ($laps ?? [] as $lap) {
            $elapsed = is_numeric($lap['elapsed_sec'] ?? null) ? (float) $lap['elapsed_sec'] : null;
            if ($elapsed === null || $elapsed <= 0) {
                continue;
            }

            if (self::paceSecondsPerKm(is_string($lap['pace'] ?? null) ? $lap['pace'] : null) !== null
                || (is_numeric($lap['distance_m'] ?? null) && (float) $lap['distance_m'] > 0)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, float|null> $values */
    private static function median(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn (?float $value): bool => $value !== null));
        sort($values, SORT_NUMERIC);
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
