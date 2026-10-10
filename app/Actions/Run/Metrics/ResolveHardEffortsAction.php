<?php

declare(strict_types=1);

namespace App\Actions\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\RunDistanceTimes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The athlete's unconfirmed hard whole-run efforts and their other runs, read
 * in date order and cached until the rows they are read from change.
 *
 * A run is a hard effort when it set a distance record on its own date and the
 * record covers essentially the whole run. A fast segment inside a longer run
 * never counts, and neither the Strava workout tag nor heart rate plays a part:
 * a tag edited after ingest never reaches the app.
 *
 * @phpstan-type HardEffort array{activity_id: int, date: Carbon, distance_m: float, time_sec: float, basis: string}
 * @phpstan-type TrainingRun array{activity_id: int, date: Carbon, distance_m: float, time_sec: float}
 * @phpstan-type Efforts array{efforts: list<HardEffort>, runs: list<TrainingRun>}
 * @phpstan-type CachedEfforts array{efforts: list<array{activity_id: int, date: string, distance_m: float, time_sec: float, basis: string}>, runs: list<array{activity_id: int, date: string, distance_m: float, time_sec: float}>}
 */
class ResolveHardEffortsAction
{
    public const float MIN_METERS = 3_000.0;

    public const float WHOLE_RUN_TOLERANCE = 0.10;

    private const int CACHE_VERSION = 1;

    private const int CACHE_TTL_SECONDS = 86_400;

    /** @var array<int, Efforts> */
    private array $memo = [];

    /** @return Efforts */
    public function __invoke(int $userId): array
    {
        if (isset($this->memo[$userId])) {
            return $this->memo[$userId];
        }

        /** @var CachedEfforts $cached */
        $cached = DB::transaction(fn (): array => Cache::remember(self::cacheKey($userId), self::CACHE_TTL_SECONDS, fn (): array => self::dehydrate($this->resolve($userId))));

        return $this->memo[$userId] = self::hydrate($cached);
    }

    public function forget(int $userId): void
    {
        unset($this->memo[$userId]);
    }

    private static function cacheKey(int $userId): string
    {
        $fingerprint = self::details($userId)
            ->toBase()
            ->selectRaw("count(*) as details, coalesce(sum(crc32(concat_ws('|', activity_details.activity_id, activity_details.start_date_local, ifnull(activity_details.distance, '-'), ifnull(activity_details.elapsed_time, '-'), ifnull(activity_details.moving_time, '-'), ifnull(activity_details.stream_summary, '-')))), 0) as checksum")
            ->first();

        return 'hard-efforts:v'.self::CACHE_VERSION.":{$userId}:{$fingerprint?->details}:{$fingerprint?->checksum}";
    }

    /** @return Builder<ActivityDetail> */
    private static function details(int $userId): Builder
    {
        return Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local');
    }

    /**
     * @param  Efforts  $resolved
     * @return CachedEfforts
     */
    private static function dehydrate(array $resolved): array
    {
        return [
            'efforts' => array_map(static fn (array $effort): array => [...$effort, 'date' => $effort['date']->toDateTimeString()], $resolved['efforts']),
            'runs' => array_map(static fn (array $run): array => [...$run, 'date' => $run['date']->toDateTimeString()], $resolved['runs']),
        ];
    }

    /**
     * @param  CachedEfforts  $cached
     * @return Efforts
     */
    private static function hydrate(array $cached): array
    {
        return [
            'efforts' => array_map(static fn (array $effort): array => [...$effort, 'date' => Carbon::parse($effort['date'])], $cached['efforts']),
            'runs' => array_map(static fn (array $run): array => [...$run, 'date' => Carbon::parse($run['date'])], $cached['runs']),
        ];
    }

    /** @return Efforts */
    private function resolve(int $userId): array
    {
        $details = self::details($userId)
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
