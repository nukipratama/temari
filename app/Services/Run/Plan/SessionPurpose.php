<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
use App\Services\Run\Metrics\DurationFormatter;

/**
 * What a session is for and how it should feel, in one line, as Plan, Today
 * and the intervals.icu workout show it.
 */
final class SessionPurpose
{
    private const array RACE = [
        GoalPaceWork::KIND_5K => '5K',
        GoalPaceWork::KIND_10K => '10K',
        GoalPaceWork::KIND_HALF => 'half marathon',
        GoalPaceWork::KIND_MARATHON => 'marathon',
    ];

    /**
     * @param  list<SessionSegment>  $segments
     * @param  array{pace_kind: string, pace_source: string}|null  $fastFinish
     * @param  array{pace_kind: string, pace_source: string}|null  $raceLong
     * @param  array{distance_m: int, aim_time_sec: int}|null  $timeTrial
     */
    public static function of(
        SessionType $type,
        array $segments,
        ?string $goalPace,
        bool $steppingStone,
        bool $closesAtGoalPace,
        ?array $fastFinish,
        ?array $raceLong,
        ?array $timeTrial,
    ): ?string {
        if ($timeTrial !== null) {
            return 'aim around '.DurationFormatter::hms($timeTrial['aim_time_sec']).'. checks your fitness so your paces stay honest.';
        }
        if ($goalPace !== null) {
            $closing = $closesAtGoalPace ? ', closing at goal pace' : '';

            return 'rehearsing your '.self::RACE[$goalPace].' '.($steppingStone ? 'stepping-stone pace' : 'goal pace')."{$closing}.";
        }
        if ($fastFinish !== null) {
            return 'the last fifth at your '.self::sourced($fastFinish).'. rehearses race pace on tired legs.';
        }
        if ($raceLong !== null) {
            return 'long run with a block at your '.self::sourced($raceLong).'. rehearses race day on tired legs.';
        }

        $hasWork = array_any($segments, static fn (SessionSegment $segment): bool => $segment->zone > 'Z2');

        return match ($type) {
            SessionType::Long => $hasWork
                ? 'long run with goal-pace work. rehearses race day on tired legs.'
                : 'time on feet. builds the engine the race runs on. chatty effort the whole way.',
            SessionType::Tempo => $hasWork ? 'comfortably hard. teaches you to hold a pace without tipping over.' : null,
            SessionType::Interval => $hasWork ? 'short, hard reps. lifts your ceiling so race pace feels roomier.' : null,
            SessionType::Easy => "easy means easy. slow enough to talk, that's the whole point.",
            SessionType::Rest => 'rest day. this is where the training actually lands.',
            SessionType::Race => 'race day. trust the work, and start slower than you want to.',
        };
    }

    /**
     * The pace a fast block runs at, named by its source and race.
     *
     * @param  array{pace_kind: string, pace_source: string}  $pace
     */
    private static function sourced(array $pace): string
    {
        $race = self::RACE[$pace['pace_kind']];

        return match ($pace['pace_source']) {
            FastFinishLongRun::PACE_GOAL => "{$race} goal pace",
            FastFinishLongRun::PACE_STEPPING_STONE => "{$race} stepping-stone pace",
            default => "supported {$race} pace",
        };
    }
}
