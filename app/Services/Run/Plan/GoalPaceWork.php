<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\RaceAmbitionState;
use App\Enums\RaceSupport;
use App\Enums\SessionType;
use Illuminate\Support\Carbon;

/**
 * The race-pace rehearsal one quality session a week becomes in the last
 * weeks before a race the athlete's fitness supports. See
 * `docs/decisions/goal-pace-work-in-the-last-weeks.md`.
 */
final readonly class GoalPaceWork
{
    public const int SHORT_RACE_WINDOW_WEEKS = 6;

    public const int LONG_RACE_WINDOW_WEEKS = 8;

    public const float SHORT_RACE_MAX_M = 10_000.0;

    public const float FIVE_K_MAX_M = 5_000.0;

    public const string KIND_5K = '5k';

    public const string KIND_10K = '10k';

    public const string KIND_HALF = 'half';

    public const string KIND_MARATHON = 'marathon';

    private const array BANDS = [RaceAmbitionState::OnTrack, RaceAmbitionState::Ambitious];

    private const array PHASES = [PlanPhase::Build, PlanPhase::Peak, PlanPhase::Taper];

    public function __construct(
        public string $kind,
        public int $distanceM,
        public int $goalPaceSecPerKm,
        public RaceAmbitionState $band,
    ) {
    }

    public static function forWeek(PlanInputs $inputs, Carbon $weekStart, PlanPhase $phase): ?self
    {
        $distanceM = $inputs->raceDistanceM;
        $goalTimeSec = $inputs->raceGoalTimeSec;
        if ($inputs->raceDate === null || $distanceM === null || $goalTimeSec === null || $goalTimeSec <= 0
            || ! RaceSupport::forDistance($distanceM)->dedicatedPreparation()
            || ! in_array($inputs->raceAmbitionState, self::BANDS, true)
            || ! in_array($phase, self::PHASES, true)
            || ! self::inWindow($weekStart, $inputs->raceDate, $distanceM)) {
            return null;
        }

        return new self(
            self::kindFor($distanceM),
            (int) round($distanceM),
            (int) round($goalTimeSec / ($distanceM / 1000)),
            $inputs->raceAmbitionState,
        );
    }

    public static function inWindow(Carbon $weekStart, Carbon $raceDate, float $distanceM): bool
    {
        $raceWeekStart = $raceDate->copy()->startOfWeek(Carbon::MONDAY);
        $weeksOut = (int) $weekStart->copy()->startOfWeek(Carbon::MONDAY)->diffInWeeks($raceWeekStart) + 1;

        return $weeksOut >= 1 && $weeksOut <= self::windowWeeks($distanceM);
    }

    public static function windowWeeks(float $distanceM): int
    {
        return $distanceM <= self::SHORT_RACE_MAX_M ? self::SHORT_RACE_WINDOW_WEEKS : self::LONG_RACE_WINDOW_WEEKS;
    }

    public static function kindFor(float $distanceM): string
    {
        return match (true) {
            $distanceM <= self::FIVE_K_MAX_M => self::KIND_5K,
            $distanceM <= self::SHORT_RACE_MAX_M => self::KIND_10K,
            RaceSupport::isMarathonClass($distanceM) => self::KIND_MARATHON,
            default => self::KIND_HALF,
        };
    }

    public function isMarathon(): bool
    {
        return $this->kind === self::KIND_MARATHON;
    }

    public function racesLongAtGoalPace(): bool
    {
        return $this->isMarathon() && $this->band === RaceAmbitionState::OnTrack;
    }

    public function paceBand(): PaceBand
    {
        return match ($this->kind) {
            self::KIND_5K => PaceBand::Interval,
            self::KIND_MARATHON => PaceBand::Marathon,
            default => PaceBand::Threshold,
        };
    }

    /** @return array{distance_m: int, goal_pace_sec_per_km: int, kind: string, band: string} */
    public function context(): array
    {
        return [
            'distance_m' => $this->distanceM,
            'goal_pace_sec_per_km' => $this->goalPaceSecPerKm,
            'kind' => $this->kind,
            'band' => $this->band->value,
        ];
    }

    /**
     * The week's one session that becomes goal-pace work: the first of the
     * kind's own form, otherwise the first Tempo or Interval.
     *
     * @param  array<string, array{session_type: SessionType, ...}>  $rows
     */
    public function replacedDate(array $rows): ?string
    {
        $quality = array_filter($rows, static fn (array $row): bool => in_array($row['session_type'], [SessionType::Tempo, SessionType::Interval], true));
        ksort($quality);
        $form = self::formFor($this->kind);

        return array_find_key($quality, static fn (array $row): bool => $row['session_type'] === $form)
            ?? array_key_first($quality);
    }

    /** @param  array<string, int|float|string>|null  $context */
    public static function isGoalPace(?array $context): bool
    {
        return isset($context['band']);
    }

    /**
     * The structure a session is written in: reps for a 5K or 10K goal pace,
     * one continuous block for the half and the marathon, otherwise its own type.
     *
     * @param  array<string, int|float|string>|null  $context
     */
    public static function shapeOf(SessionType $type, ?array $context): SessionType
    {
        if ($type === SessionType::Long || ! self::isGoalPace($context)) {
            return $type;
        }

        return self::formFor($context['kind'] ?? null);
    }

    private static function formFor(int|float|string|null $kind): SessionType
    {
        return in_array($kind, [self::KIND_5K, self::KIND_10K], true) ? SessionType::Interval : SessionType::Tempo;
    }
}
