<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\AdaptationReason;
use App\Enums\PlannedSessionStatus;
use App\Enums\PlanOverrideKind;
use App\Enums\SessionType;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The athlete's one "i'm ready" override a week: today's quality session the readiness clamp eased
 * at a {@see ReadinessCeiling::ModerateOk} ceiling, the quality session a ran-too-hard week
 * dropped, back on its natural day, a session the weekly hard-minute cap trimmed, or one the weekly
 * hard-day count made easy. See `docs/decisions/an-athlete-can-override-an-eased-quality-session.md`,
 * `docs/decisions/a-budget-dropped-session-can-be-brought-back.md` and
 * `docs/decisions/a-hard-day-dropped-session-can-be-brought-back.md`.
 */
final class QualityOverride
{
    public const string TAKEN_NOTE = 'you chose to run this one as written.';

    /**
     * The week's adaptation while this athlete may override it: never the demo athlete, and never
     * in the {@see PreRaceWindow} unless the override was already taken.
     */
    public static function openWeek(User $user, ?PlanAdaptation $week, ?RaceGoal $race): ?PlanAdaptation
    {
        if (! $week instanceof PlanAdaptation || $user->is_demo
            || (! $week->override_kind instanceof PlanOverrideKind && PreRaceWindow::contains($week->week_start, $race?->race_date))) {
            return null;
        }

        return $week;
    }

    /**
     * @param  PlanAdaptation|null  $week  the day's week while {@see self::openWeek()} holds it open
     * @param  string|null  $easeNote  the clamp's note on the ease this day shows
     * @param  ReadinessCeiling|null  $easeCeiling  the ceiling that ease was made at
     * @param  string|null  $takenOn  the day the taken note sits on, from {@see self::takenOn()}
     * @return array{kind: string, state: 'offered', label: string, reason: string}|array{kind: string, state: 'taken', note: string, undoable: bool}|null
     */
    public static function forDay(
        PlannedSession $day,
        Carbon $today,
        PlannedSessionStatus $status,
        bool $ran,
        ?PlanAdaptation $week,
        ?string $easeNote,
        ?ReadinessCeiling $easeCeiling,
        ?string $takenOn = null,
    ): ?array {
        if (! $week instanceof PlanAdaptation || ! $week->week_start->isSameDay($day->date->copy()->startOfWeek(Carbon::MONDAY))) {
            return null;
        }

        $overrideDate = $week->override_date?->toDateString();
        if ($week->override_kind instanceof PlanOverrideKind && ($takenOn ?? $overrideDate) === $day->date->toDateString()) {
            return [
                'kind' => $week->override_kind->value,
                'state' => 'taken',
                'note' => self::TAKEN_NOTE,
                'undoable' => $overrideDate === $day->date->toDateString() && ! $ran && ! $status->isCredited() && ! $day->date->lt($today),
            ];
        }

        if ($week->override_kind instanceof PlanOverrideKind || $ran || $day->pinned || $day->skipped
            || $status !== PlannedSessionStatus::Planned || $day->date->lt($today)) {
            return null;
        }

        if ($day->date->isSameDay($today) && $easeNote !== null && $easeCeiling === ReadinessCeiling::ModerateOk && self::isWorkout($day->session_type)) {
            return ['kind' => PlanOverrideKind::ReadinessEase->value, 'state' => 'offered', 'label' => "i'm ready", 'reason' => $easeNote];
        }

        $budget = $day->prescription_budget;
        if ($budget !== null) {
            $cost = $budget['needed_minutes'] - $budget['left_minutes'];

            return [
                'kind' => PlanOverrideKind::BudgetDrop->value,
                'state' => 'offered',
                'label' => $cost > 0 ? "bring it back · {$cost} hard min over this week's cap" : 'bring it back',
                'reason' => "this week has {$budget['left_minutes']} of its {$budget['cap_minutes']} hard minutes left, and this needs {$budget['needed_minutes']}.",
            ];
        }
        if ($day->hard_day_restorable) {
            return [
                'kind' => PlanOverrideKind::HardDayDrop->value,
                'state' => 'offered',
                'label' => 'bring it back · a third hard day this week',
                'reason' => 'this week already has two hard days, so the plan made this one easy.',
            ];
        }
        $restorable = $week->restorable_quality;
        if ($restorable !== null && $restorable['date'] === $day->date->toDateString()
            && $week->reason === AdaptationReason::RanTooHard && $week->quality_delta < 0 && ! self::isWorkout($day->session_type)) {
            return [
                'kind' => PlanOverrideKind::WeeklyDrop->value,
                'state' => 'offered',
                'label' => 'bring back '.strtolower($day->date->format('l'))."'s ".($restorable['time_trial'] ? 'time trial' : $restorable['session_type']),
                'reason' => $week->reason->detail($week->adherence_pct, $week->stimulus_adherence_pct, $week->quality_delta),
            ];
        }

        return null;
    }

    /**
     * The day a taken override's note sits on: its own date, or the day its
     * session was made up on.
     *
     * @param  Collection<int, PlannedSession>  $weekRows
     */
    public static function takenOn(?PlanAdaptation $week, Collection $weekRows): ?string
    {
        $overrideDate = $week?->override_kind instanceof PlanOverrideKind ? $week->override_date?->toDateString() : null;
        if ($overrideDate === null) {
            return null;
        }
        $overrideDay = $weekRows->first(static fn (PlannedSession $row): bool => $row->date->toDateString() === $overrideDate);

        return $overrideDay?->made_up_on?->toDateString() ?? $overrideDate;
    }

    public static function holdsEase(?PlanAdaptation $week, Carbon $today, ReadinessCeiling $ceiling): bool
    {
        return $ceiling === ReadinessCeiling::ModerateOk && $week?->overrides(PlanOverrideKind::ReadinessEase, $today) === true;
    }

    public static function isWorkout(SessionType $type): bool
    {
        return in_array($type, [SessionType::Tempo, SessionType::Interval], true);
    }
}
