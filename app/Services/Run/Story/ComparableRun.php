<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\IngestState;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\PaceCalculator;
use Illuminate\Support\Carbon;

/**
 * A run reduced to the fields `/athlete/activities` already returns, so a run
 * still waiting on lazy detail hydration is a first-class comparison candidate.
 * Nothing here reads streams, splits, TRIMP or weather.
 */
final readonly class ComparableRun
{
    public function __construct(
        public int $activityId,
        public Carbon $startedAt,
        public float $distanceM,
        public int $movingTimeSec,
        public float $paceSecPerKm,
        public ?float $averageHeartrate,
        public ?float $elevationGainM,
        public IngestState $ingestState,
    ) {
    }

    /**
     * Built from a plain query record — `activity_id`, `start_date_local`,
     * `distance`, `moving_time`, `average_heartrate`, `total_elevation_gain`
     * and the owning activity's `ingest_state` — so a year of history can be
     * read without hydrating a model per run.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $distance = (float) ($row['distance'] ?? 0);
        $movingTime = (int) ($row['moving_time'] ?? 0);
        $pace = PaceCalculator::secPerKm($distance, $movingTime);
        $startedAt = $row['start_date_local'] ?? null;

        if ($pace === null || ! is_string($startedAt) || $distance <= 0.0 || $movingTime <= 0) {
            return null;
        }

        return new self(
            activityId: (int) $row['activity_id'],
            startedAt: Carbon::parse($startedAt),
            distanceM: $distance,
            movingTimeSec: $movingTime,
            paceSecPerKm: $pace,
            averageHeartrate: isset($row['average_heartrate']) ? (float) $row['average_heartrate'] : null,
            elevationGainM: isset($row['total_elevation_gain']) ? (float) $row['total_elevation_gain'] : null,
            ingestState: IngestState::from((string) $row['ingest_state']),
        );
    }

    public function distanceKm(): float
    {
        return $this->distanceM / 1000;
    }

    public function elevationPerKm(): ?float
    {
        return $this->elevationGainM === null ? null : $this->elevationGainM / $this->distanceKm();
    }

    /** Minutes since local midnight, so an early-morning run isn't compared against an evening one for free. */
    public function minuteOfDay(): int
    {
        return $this->startedAt->hour * 60 + $this->startedAt->minute;
    }

    public function month(): int
    {
        return (int) $this->startedAt->month;
    }

    public function daysBefore(self $later): int
    {
        return (int) $this->startedAt->copy()->startOfDay()
            ->diffInDays($later->startedAt->copy()->startOfDay());
    }

    /**
     * @return array{activity_id: int, date: string, km: float, pace_sec_per_km: float, average_heartrate: float|null, elevation_gain_m: float|null, ingest_state: string}
     */
    public function toArray(): array
    {
        return [
            'activity_id' => $this->activityId,
            'date' => $this->startedAt->toDateString(),
            'km' => DistanceFormatter::km($this->distanceM),
            'pace_sec_per_km' => round($this->paceSecPerKm, 1),
            'average_heartrate' => $this->averageHeartrate === null ? null : round($this->averageHeartrate, 1),
            'elevation_gain_m' => $this->elevationGainM === null ? null : round($this->elevationGainM, 1),
            'ingest_state' => $this->ingestState->value,
        ];
    }
}
