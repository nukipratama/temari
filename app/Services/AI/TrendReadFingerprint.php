<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Models\AI\Analysis;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\AI\Agent\Tools\PlanAdherenceTool;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * The material the Trends read speaks to: the six closed weeks ending last Sunday, plan adherence
 * over them, and the active race's identity, distance, target and date. A run in the week still
 * open never moves it.
 */
final readonly class TrendReadFingerprint
{
    public function __construct(
        private TrainingLoad $trainingLoad,
        private ResolveActiveRaceAction $activeRace,
    ) {
    }

    public static function closedThrough(): Carbon
    {
        return Carbon::parse(RecapPeriod::lastClosedWeekEnding());
    }

    public static function windowStart(Carbon $through): Carbon
    {
        return $through->copy()->subDays(TrendRangeTool::RANGE_DAYS['6w'] - 1);
    }

    public function forUser(User $user): string
    {
        $through = self::closedThrough();
        $totals = new TrendRangeTool($user, '6w', $this->trainingLoad, $through)->handle([]);
        $adherence = new PlanAdherenceTool($user, $through, self::windowStart($through))->handle([]);
        $race = ($this->activeRace)($user->id);

        return MaterialFingerprint::forTrendRead($totals, $adherence, $race instanceof RaceGoal ? [
            'id' => $race->id,
            'distance_m' => $race->distance_m,
            'goal_time_sec' => $race->goal_time_sec,
            'race_date' => $race->race_date->toDateString(),
        ] : null);
    }

    public function changed(User $user): bool
    {
        $row = Analysis::query()
            ->forSubject(AnalysisType::TREND_READ_SUBJECT_TYPE, $user->id, AnalysisType::TrendRead, AnalysisType::TREND_READ_RANGES[0])
            ->first();

        if ($row === null || $row->status !== AnalysisStatus::Done) {
            return false;
        }

        return $row->content_fingerprint === null
            || $row->content_fingerprint !== $this->forUser($user);
    }
}
