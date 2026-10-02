<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\RaceOutcome;
use App\Enums\SeasonPerformance;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * A season's record, kept in two separate halves: the process (how much of the training
 * the athlete did) and the performance (what the race result was against its target).
 * A missed time goal never lowers the process, and a pending result is never a miss.
 *
 * @phpstan-type Record array{process: array{pct: int|null, goals_met: int, goals_total: int}, performance: array{state: string, target_time_sec: int|null, finish_time_sec: int|null, margin_pct: float|null}}
 */
final readonly class SeasonRecordBuilder
{
    private const string RACE_GOAL_METRIC = 'season_race_goal_met';

    public function __construct(
        private SeasonGoalResolver $goals,
        private TrainingLoad $trainingLoad,
    ) {
    }

    /**
     * @return Record
     */
    public function build(User $user, Season $season, Carbon $today, ?SeasonGamificationContext $context = null): array
    {
        $context ??= SeasonGamificationContext::forSeason($user, $season, $today->copy()->startOfDay(), $this->trainingLoad);

        $fractions = [];
        $met = 0;
        foreach ($season->goals as $goal) {
            if ($goal->metric === self::RACE_GOAL_METRIC || $goal->target <= 0) {
                continue;
            }
            $current = $this->goals->currentValue($context, $goal->metric);
            $fractions[] = min(1.0, $current / $goal->target);
            $met += $current >= $goal->target ? 1 : 0;
        }

        return [
            'process' => [
                'pct' => $fractions === [] ? null : (int) round(array_sum($fractions) / count($fractions) * 100),
                'goals_met' => $met,
                'goals_total' => count($fractions),
            ],
            'performance' => self::performance($season->raceGoal),
        ];
    }

    public function settle(User $user, Season $season, Carbon $today): void
    {
        $record = $this->build($user, $season, $today);

        $season->update([
            'process_pct' => $record['process']['pct'],
            'performance_state' => SeasonPerformance::from($record['performance']['state']),
            'record_settled_at' => $season->record_settled_at ?? now(),
        ]);
    }

    /**
     * Re-reads the performance of the seasons already settled for this race, so a late confirmation or correction reaches them.
     */
    public function settleForRace(RaceGoal $race): void
    {
        $performance = SeasonPerformance::from(self::performance($race)['state']);

        Season::query()
            ->where('race_goal_id', $race->id)
            ->whereNotNull('record_settled_at')
            ->update(['performance_state' => $performance]);
    }

    /**
     * @return array{state: string, target_time_sec: int|null, finish_time_sec: int|null, margin_pct: float|null}
     */
    private static function performance(?RaceGoal $race): array
    {
        if ($race === null) {
            return ['state' => SeasonPerformance::None->value, 'target_time_sec' => null, 'finish_time_sec' => null, 'margin_pct' => null];
        }

        $state = match ($race->outcome) {
            null => SeasonPerformance::Unrecorded,
            RaceOutcome::Pending => SeasonPerformance::Pending,
            RaceOutcome::DidNotRun => SeasonPerformance::DidNotRun,
            RaceOutcome::Cancelled => SeasonPerformance::Cancelled,
            RaceOutcome::Confirmed => $race->finish_time_sec !== null
                && $race->finish_time_sec <= $race->goal_time_sec * (1 + SeasonGamificationContext::RACE_MARGIN_FRACTION)
                    ? SeasonPerformance::Met
                    : SeasonPerformance::NotMet,
        };
        $finish = $race->outcome === RaceOutcome::Confirmed ? $race->finish_time_sec : null;

        return [
            'state' => $state->value,
            'target_time_sec' => $race->goal_time_sec,
            'finish_time_sec' => $finish,
            'margin_pct' => $finish === null ? null : round(($finish - $race->goal_time_sec) / $race->goal_time_sec * 100, 1),
        ];
    }
}
