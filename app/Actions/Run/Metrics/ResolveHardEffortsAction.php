<?php

declare(strict_types=1);

namespace App\Actions\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\RunDistanceTimes;
use Illuminate\Support\Carbon;

/**
 * The athlete's unconfirmed hard whole-run efforts and their other runs, read
 * once per request in date order.
 *
 * A run is a hard effort when it set a distance record on its own date and the
 * record covers essentially the whole run. A fast segment inside a longer run
 * never counts, and neither the Strava workout tag nor heart rate plays a part:
 * a tag edited after ingest never reaches the app.
 *
 * @phpstan-type HardEffort array{activity_id: int, date: Carbon, distance_m: float, time_sec: float, basis: string}
 * @phpstan-type TrainingRun array{activity_id: int, date: Carbon, distance_m: float, time_sec: float}
 * @phpstan-type Efforts array{efforts: list<HardEffort>, runs: list<TrainingRun>}
 */
class ResolveHardEffortsAction
{
    public const float MIN_METERS = 3_000.0;

    public const float WHOLE_RUN_TOLERANCE = 0.10;

    /** @var array<int, Efforts> */
    private array $memo = [];

    /** @return Efforts */
    public function __invoke(int $userId): array
    {
        return $this->memo[$userId] ??= $this->resolve($userId);
    }

    public function forget(int $userId): void
    {
        unset($this->memo[$userId]);
    }

    /** @return Efforts */
    private function resolve(int $userId): array
    {
        $details = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->orderBy('activity_details.start_date_local')
            ->orderBy('activity_details.activity_id')
            ->select([
                'activity_details.activity_id', 'activity_details.start_date_local', 'activity_details.distance',
                'activity_details.elapsed_time', 'activity_details.moving_time',
                'activity_details.stream_summary',
            ])
            ->cursor();

        $best = [];
        $efforts = [];
        $runs = [];

        foreach ($details as $detail) {
            $distance = (float) ($detail->distance ?? 0);
            $time = (float) ($detail->elapsed_time ?? $detail->moving_time ?? 0);
            $date = $detail->start_date_local;
            if ($date === null) {
                continue;
            }

            $recordEffort = null;
            foreach (RunDistanceTimes::forDetail($detail) as $category => $value) {
                $setsRecord = ! isset($best[$category]) || $value < $best[$category];
                if (! $setsRecord) {
                    continue;
                }
                $best[$category] = $value;
                $categoryMeters = (float) PrCategory::from($category)->distanceMeters();
                if ($categoryMeters >= self::MIN_METERS
                    && abs($distance - $categoryMeters) / $categoryMeters <= self::WHOLE_RUN_TOLERANCE) {
                    $recordEffort = ['distance_m' => $categoryMeters, 'time_sec' => $value, 'basis' => $category];
                }
            }

            if ($distance < self::MIN_METERS || $time <= 0) {
                continue;
            }

            if ($recordEffort === null) {
                $runs[] = ['activity_id' => $detail->activity_id, 'date' => $date, 'distance_m' => $distance, 'time_sec' => $time];
            } else {
                $efforts[] = ['activity_id' => $detail->activity_id, 'date' => $date, ...$recordEffort];
            }
        }

        return ['efforts' => $efforts, 'runs' => $runs];
    }
}
