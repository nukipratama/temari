<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\DurationFormatter;
use App\Services\Run\Plan\RacePresenter;
use App\Services\Run\Plan\SupportedTimeHistory;
use Illuminate\Support\Carbon;

/**
 * The active race as the Race and Trends pages show it: the target against the time recent running
 * supports, and which way that supported time has moved across the season.
 */
final class RaceOutlookTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly RaceGoal $race,
        private readonly RacePresenter $presenter,
        private readonly SupportedTimeHistory $history,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_race_outlook';
    }

    public function description(): string
    {
        return 'The race the athlete is training for: its distance, date, days and whole weeks left, the '
            .'target time, and the time their recent running supports at that distance (null when '
            .'there is not enough evidence yet). ambition is already decided: on_track, ambitious, '
            .'unsupported, low_evidence or unknown. gap is the size of the difference between target '
            .'and supported time as a pct plus a relation (target_faster, target_slower or level), '
            .'there is no sign to read yourself. support_trend is how the supported time moved across '
            .'this season, from its first reading to the latest (faster, slower or flat), null with '
            .'fewer than two readings. dedicated_preparation false means the distance is past what '
            .'the plan prepares for, so the supported time is not read at all.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $presented = $this->presenter->present($this->user, $this->race);
        $ambition = $presented['ambition'];
        $daysToRace = max(0, (int) $this->asOf->copy()->startOfDay()->diffInDays($this->race->race_date->copy()->startOfDay(), false));
        $supportedSec = $ambition['supported_time_sec'];
        $gapPct = $ambition['gap_pct'];
        assert(is_string($ambition['state']));
        assert($supportedSec === null || is_int($supportedSec));
        assert($gapPct === null || is_float($gapPct));

        return [
            'distance_km' => DistanceFormatter::km((float) $this->race->distance_m),
            'race_date' => $this->race->race_date->toDateString(),
            'days_to_race' => $daysToRace,
            'weeks_to_race' => intdiv($daysToRace, 7),
            'target_time' => DurationFormatter::hms($this->race->goal_time_sec),
            'supported_time' => $supportedSec === null ? null : DurationFormatter::hms($supportedSec),
            'ambition' => $ambition['state'],
            'gap' => $gapPct === null ? null : [
                'pct' => abs($gapPct),
                'relation' => match (true) {
                    $gapPct > 0 => 'target_faster',
                    $gapPct < 0 => 'target_slower',
                    default => 'level',
                },
            ],
            'evidence_confidence' => $ambition['evidence_confidence'],
            'support_trend' => $this->supportTrend(),
            'dedicated_preparation' => $presented['support']['dedicated_preparation'],
        ];
    }

    /** @return array{since: string, from: string, to: string, relation: string}|null */
    private function supportTrend(): ?array
    {
        $points = $this->history->for($this->user, $this->race, $this->asOf)['points'] ?? [];
        $first = array_first($points);
        $last = array_last($points);
        if ($first === null || $last === null) {
            return null;
        }

        return [
            'since' => $first['date'],
            'from' => DurationFormatter::hms($first['supported_time_sec']),
            'to' => DurationFormatter::hms($last['supported_time_sec']),
            'relation' => match ($last['supported_time_sec'] <=> $first['supported_time_sec']) {
                -1 => 'faster',
                1 => 'slower',
                default => 'flat',
            },
        ];
    }
}
