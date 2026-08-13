<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\IngestState;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\DistanceFormatter;
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

    public static function fromDetail(ActivityDetail $detail, IngestState $ingestState): ?self
    {
        $pace = $detail->paceSecPerKm();
        $startedAt = $detail->start_date_local;
        $distance = (float) ($detail->distance ?? 0);
        $movingTime = (int) ($detail->moving_time ?? 0);

        if ($pace === null || $startedAt === null || $distance <= 0.0 || $movingTime <= 0) {
            return null;
        }

        return new self(
            activityId: (int) $detail->activity_id,
            startedAt: $startedAt,
            distanceM: $distance,
            movingTimeSec: $movingTime,
            paceSecPerKm: $pace,
            averageHeartrate: $detail->average_heartrate === null ? null : (float) $detail->average_heartrate,
            elevationGainM: $detail->total_elevation_gain === null ? null : (float) $detail->total_elevation_gain,
            ingestState: $ingestState,
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
