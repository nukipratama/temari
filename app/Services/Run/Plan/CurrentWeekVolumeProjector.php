<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\DistanceFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class CurrentWeekVolumeProjector
{
    public function __construct(private SessionMatcher $sessionMatcher)
    {
    }

    /**
     * @param Collection<int, PlannedSession> $sessions
     * @param array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string}|null $clamp
     * @return array{scale_by_date: array<string, float>, activity_by_date: array<string, array{km: float, meters: float, runs: list<array{id: int, km: float, seconds: int|null, started_at: string}>}>}
     */
    public function project(
        User $user,
        Collection $sessions,
        Carbon $activityFrom,
        Carbon $today,
        float $longRunKm,
        float $multiplier,
        float $longRunCapKm,
        float $longRunProgressionCapKm,
        ?string $primaryEasyDate,
        ?PlannedSession $todaySession,
        ?array $clamp,
    ): array {
        $activityByDate = $this->sessionMatcher->activityByDate($user, $activityFrom, $today);

        if ($sessions->isEmpty()) {
            return ['scale_by_date' => [], 'activity_by_date' => $activityByDate];
        }

        $kmFor = fn (PlannedSession $session): float => EffectiveSession::of($session, SegmentGenerator::coreKmFor(
            $session->session_type,
            $session->date->toDateString() === $primaryEasyDate,
            $longRunKm,
            $multiplier,
            $longRunCapKm,
            longRunProgressionCapKm: $longRunProgressionCapKm,
            fallOffTilt: $session->fall_off_tilt,
            raceContext: $session->prescription_race_context,
        ))->coreKm;

        $weekTargetKm = $sessions->sum($kmFor);
        /** @var string $planStart */
        $planStart = $sessions->min(fn (PlannedSession $session): string => $session->date->toDateString());
        $completedKm = $this->completedKmInRange($activityByDate, $planStart, $today->copy()->subDay());
        $pinnedKm = $sessions->filter(fn (PlannedSession $session): bool => $session->pinned && ! $session->date->lt($today))->sum($kmFor);

        $todayFixedKm = 0.0;
        if ($todaySession !== null && ! $todaySession->pinned) {
            $todayFixedKm = $clamp !== null && ! EffectiveSession::isRecordedOn($todaySession)
                ? $clamp['core_km']
                : $kmFor($todaySession);
        }

        $easyDaysKm = [];
        $keySessionsKm = 0.0;
        foreach ($sessions as $session) {
            if ($session->pinned || ! $session->date->isAfter($today)) {
                continue;
            }

            if ($this->runsEasy($session)) {
                $easyDaysKm[$session->date->toDateString()] = $kmFor($session);
            } else {
                $keySessionsKm += $kmFor($session);
            }
        }

        $remainingEasyKm = max(0.0, $weekTargetKm - $completedKm - $pinnedKm - $todayFixedKm - $keySessionsKm);

        return [
            'scale_by_date' => VolumeRedistributor::redistribute($easyDaysKm, $remainingEasyKm),
            'activity_by_date' => $activityByDate,
        ];
    }

    private function runsEasy(PlannedSession $session): bool
    {
        return $session->session_type === SessionType::Easy
            || (in_array($session->session_type, [SessionType::Tempo, SessionType::Interval], true)
                && IntensityPrescription::fromSession($session)?->isEasy() === true);
    }

    /** @param array<string, array{meters: float}> $activityByDate */
    private function completedKmInRange(array $activityByDate, string $from, Carbon $to): float
    {
        if ($to->lessThan(Carbon::parse($from))) {
            return 0.0;
        }

        $toDate = $to->toDateString();
        $meters = 0.0;
        foreach ($activityByDate as $date => $activity) {
            if ($date >= $from && $date <= $toDate) {
                $meters += $activity['meters'];
            }
        }

        return DistanceFormatter::km((float) $meters);
    }
}
