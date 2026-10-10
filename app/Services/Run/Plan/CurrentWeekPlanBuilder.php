<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Home's "this week's plan" widget — the current week only, no lookahead,
 * with the same current-week volume projection as {@see PlanPageAssembler}
 * and its week total from {@see CurrentWeekKm}.
 * Both callers query the same trailing-history window, so
 * {@see PlanRenderer::weekPhasesAndMultipliers()} computes an identical
 * multiplier for the shared week.
 */
final readonly class CurrentWeekPlanBuilder
{
    public function __construct(
        private CurrentWeekKm $currentWeekKm,
        private ClampVoiceReader $clampVoiceReader,
        private RaceAmbitionAssessor $ambition,
    ) {
    }

    /**
     * @return array{sessions_this_week: int, phase: string, planned_km_this_week: float, planned_km_eased_from: float|null, credited_this_week: int, days: array<int, array<string, mixed>>}|null
     */
    public function forUser(User $user, Carbon $today): ?array
    {
        $week = $this->currentWeekKm->forUser($user, $today);
        if ($week === null) {
            return null;
        }

        $currentWeekSessions = $week['sessions'];
        $baselineData = $week['baseline'];
        $race = $week['race'];
        $clamp = $week['clamp'];
        $resolvedStatuses = $week['statuses'];

        $clampVoice = EffectiveSession::clampVoiceNeeded($clamp, $week['today_session'])
            ? $this->clampVoiceReader->clampVoiceFor($user, $today)
            : null;

        $days = $currentWeekSessions->map(fn (PlannedSession $s): array => PlanRenderer::dayPayload(
            $s,
            $today,
            $clamp,
            $week['scale_by_date'],
            $week['race_distance_m'],
            $s->date->toDateString() === $week['primary_easy_date'],
            $baselineData['long_run_km'],
            $week['multiplier'],
            $baselineData['long_run_cap_km'],
            $week['paces'],
            $resolvedStatuses[$s->date->toDateString()] ?? PlannedSessionStatus::Planned,
            $week['activity_by_date'][$s->date->toDateString()] ?? null,
            $clampVoice,
            $race !== null && $s->date->isSameDay($race->race_date) ? $this->ambition->assess($user, $race, $today)->prescribedTimeSec() : null,
            $baselineData['long_run_progression_cap_km'],
            $week['fallback_verdicts'][$s->date->toDateString()]['ran_anyway'] ?? null,
            $s->date->isSameDay($today) ? $week['briefing']->readinessAssessment : null,
            $user->runnerProfile?->easyHrCapBpm(),
            includeRecommendationToken: $s->date->isSameDay($today),
        ))->values()->all();

        // A rest day asks for nothing and always scores Done, so counting it
        // would credit the athlete for a day off. The ring measures training
        // days, and against the ones this week actually holds — a plan that
        // began mid-week has fewer rows than the baseline's weekly target,
        // and a total nothing could reach is not a target.
        $trainingDates = $currentWeekSessions
            ->reject(fn (PlannedSession $s): bool => $s->session_type === SessionType::Rest)
            ->map(fn (PlannedSession $s): string => $s->date->toDateString())
            ->all();

        return [
            'sessions_this_week' => count($trainingDates),
            'phase' => $week['phase']->value,
            'planned_km_this_week' => $week['total_km'],
            'planned_km_eased_from' => $week['eased_from_total_km'],
            'credited_this_week' => count(array_filter(
                array_intersect_key($resolvedStatuses, array_flip($trainingDates)),
                static fn (PlannedSessionStatus $status): bool => $status->isCredited(),
            )),
            'days' => $days,
        ];
    }
}
