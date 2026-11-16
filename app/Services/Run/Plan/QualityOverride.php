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

    public const string RACE_RULE = 'the weeks closest to a race stay as the plan set them.';

    public const string ONE_A_WEEK_RULE = 'you get one bring-back a week.';

    /**
     * The week's adaptation while this athlete may override it: never the demo athlete, and never
     * in the {@see PreRaceWindow} unless the override was already taken.
     */
    public static function openWeek(User $user, ?PlanAdaptation $week, ?RaceGoal $race): ?PlanAdaptation
    {
        return $week instanceof PlanAdaptation && ! $user->is_demo && self::raceBlocker($week, $race) === null ? $week : null;
    }

    /**
     * The week's adaptation while this athlete may see its overrides, offered or locked: never the demo athlete.
     */
    public static function shownWeek(User $user, ?PlanAdaptation $week): ?PlanAdaptation
    {
        return $user->is_demo ? null : $week;
    }

    /** What keeps an untaken override shut this week near the race: "race week", or how far before it the week is. */
    public static function raceBlocker(?PlanAdaptation $week, ?RaceGoal $race): ?string
    {
        if (! $week instanceof PlanAdaptation || $week->override_kind instanceof PlanOverrideKind
            || ! $race instanceof RaceGoal || ! PreRaceWindow::contains($week->week_start, $race->race_date)) {
            return null;
        }
        $weeks = (int) $week->week_start->diffInWeeks($race->race_date->copy()->startOfWeek(Carbon::MONDAY));

        return match ($weeks) {
            0 => 'race week',
            1 => '1 week before race week',
            default => "{$weeks} weeks before race week",
        };
    }

    /**
     * @param  PlanAdaptation|null  $week  the day's week from {@see self::shownWeek()}
     * @param  string|null  $easeNote  the clamp's note on the ease this day shows
     * @param  ReadinessCeiling|null  $easeCeiling  the ceiling that ease was made at
     * @param  string|null  $takenOn  the day the taken note sits on, from {@see self::takenOn()}
     * @param  string|null  $raceBlocker  what shuts the week near its race, from {@see self::raceBlocker()}
     * @return array{kind: string, state: 'offered', label: string, reason: string}|array{kind: string, state: 'locked', blocker: string, reason: string}|array{kind: string, state: 'taken', note: string, undoable: bool}|null
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
        ?string $raceBlocker = null,
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

        if ($ran || $day->pinned || $day->skipped || $status !== PlannedSessionStatus::Planned || $day->date->lt($today)) {
            return null;
        }

        $isToday = $day->date->isSameDay($today);
        if ($isToday && $easeNote !== null && self::isWorkout($day->session_type) && in_array($easeCeiling, [ReadinessCeiling::EasyOnly, ReadinessCeiling::Rest], true)) {
            return self::locked(PlanOverrideKind::ReadinessEase, $easeCeiling === ReadinessCeiling::Rest ? 'readiness says rest today' : 'readiness says easy only today', $easeNote);
        }

        $offer = self::offerFor($day, $week, $isToday ? $easeNote : null, $easeCeiling);
        if ($offer === null) {
            return null;
        }
        if ($week->override_kind instanceof PlanOverrideKind) {
            $usedOn = strtolower(($week->override_date ?? $today)->format('l'));

            return self::locked(PlanOverrideKind::from($offer['kind']), "this week's bring-back went to {$usedOn}", $offer['reason'].' '.self::ONE_A_WEEK_RULE);
        }
        if ($raceBlocker !== null) {
            return self::locked(PlanOverrideKind::from($offer['kind']), $raceBlocker, $offer['reason'].' '.self::RACE_RULE);
        }

        return $offer;
    }

    /**
     * The quality session a ran-too-hard week dropped from this day, while the day still shows an easy run there.
     *
     * @return array{session_type: string, time_trial: bool}|null
     */
    public static function droppedFrom(PlannedSession $day, SessionType $shownType, ?PlanAdaptation $week): ?array
    {
        $dropped = $week?->dropped_quality;
        if ($dropped === null || $dropped['date'] !== $day->date->toDateString() || $shownType !== SessionType::Easy
            || $week->reason !== AdaptationReason::RanTooHard || $week->quality_delta >= 0) {
            return null;
        }

        return ['session_type' => $dropped['session_type'], 'time_trial' => $dropped['time_trial']];
    }

    /** @return array{kind: string, state: 'offered', label: string, reason: string}|null */
    private static function offerFor(PlannedSession $day, PlanAdaptation $week, ?string $easeNote, ?ReadinessCeiling $easeCeiling): ?array
    {
        if ($easeNote !== null && $easeCeiling === ReadinessCeiling::ModerateOk && self::isWorkout($day->session_type)) {
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

    /** @return array{kind: string, state: 'locked', blocker: string, reason: string} */
    private static function locked(PlanOverrideKind $kind, string $blocker, string $reason): array
    {
        return ['kind' => $kind->value, 'state' => 'locked', 'blocker' => $blocker, 'reason' => $reason];
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
