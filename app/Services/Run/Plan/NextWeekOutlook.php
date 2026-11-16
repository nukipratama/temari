<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Enums\SessionType;
use App\Models\PlanAdaptation;
use App\Models\User;
use App\Notifications\PlanChangedNotification;
use Illuminate\Support\Carbon;

/**
 * What this week's hot easy days, so far, do to next week under {@see PlanAdapter::ranTooHard()}:
 * the days that counted, the session next week loses or would lose, and whether it could be
 * brought back. Null while no easy day has run hot.
 */
final readonly class NextWeekOutlook
{
    public const string SET = 'set';

    public const string ONE_MORE = 'one_more';

    public function __construct(
        private PlanAdapter $adapter,
        private ResolveActiveRaceAction $activeRace,
    ) {
    }

    /**
     * @return array{state: 'set'|'one_more', egregious: bool, hot_days: list<array{date: string, session_type: string, over_cap_minutes: int}>, drop: array{date: string, session_type: string, time_trial: bool}|null, blocker: string|null}|null
     */
    public function for(User $user, Carbon $today): ?array
    {
        if ($user->is_demo) {
            return null;
        }

        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $execution = $this->adapter->executionBetween($user, $weekStart, $today);
        $state = match (true) {
            PlanAdapter::ranTooHard($execution['ragged'], $execution['egregious_easy']) => self::SET,
            $execution['ragged'] > 0 && PlanAdapter::ranTooHard($execution['ragged'] + 1, 0) => self::ONE_MORE,
            default => null,
        };
        if ($state === null) {
            return null;
        }

        $drop = PlanAdaptation::query()
            ->where('user_id', $user->id)
            ->where('week_start', $weekStart->toDateString())
            ->first(['next_week_drop'])
            ?->next_week_drop;

        return [
            'state' => $state,
            'egregious' => $execution['egregious_easy'] > 0,
            'hot_days' => $execution['hot_days'],
            'drop' => $drop,
            'blocker' => $drop === null ? null : QualityOverride::raceWindowBlocker($weekStart->copy()->addWeek(), ($this->activeRace)($user->id)),
        ];
    }

    /** @param array{date: string, session_type: string, over_cap_minutes: int} $day */
    public static function hotDayLine(array $day): string
    {
        return self::dayOf($day['date'])."'s ".PlanChangedNotification::label(SessionType::from($day['session_type']))
            ." went {$day['over_cap_minutes']} min over your heart-rate cap";
    }

    /** @param list<array{date: string, session_type: string, over_cap_minutes: int}> $days */
    public static function hotDaysLine(array $days): string
    {
        $named = array_map(
            static fn (array $day): string => self::dayOf($day['date'])."'s ".PlanChangedNotification::label(SessionType::from($day['session_type']))." ({$day['over_cap_minutes']} min)",
            $days,
        );
        $last = array_pop($named);

        return ($named === [] ? $last : implode(', ', $named)." and {$last}").' went over your heart-rate cap';
    }

    /** @param array{date: string, session_type: string, time_trial: bool} $drop */
    public static function dropLabel(array $drop): string
    {
        return self::dayOf($drop['date'])."'s ".($drop['time_trial'] ? 'time trial' : PlanChangedNotification::label(SessionType::from($drop['session_type'])));
    }

    public static function bringBackLine(?string $blocker, bool $would = false): string
    {
        $can = $would ? 'could' : 'can';

        return $blocker === null
            ? "you {$can} bring it back from the plan on the day."
            : "you {$can}'t bring it back: it's {$blocker}, and ".QualityOverride::RACE_RULE;
    }

    private static function dayOf(string $date): string
    {
        return strtolower(Carbon::parse($date)->format('l'));
    }
}
