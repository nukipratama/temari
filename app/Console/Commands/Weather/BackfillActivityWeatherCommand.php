<?php

declare(strict_types=1);

namespace App\Console\Commands\Weather;

use App\Services\Weather\WeatherSnapshot;
use App\Models\ActivityDetail;
use App\Services\Ops\MaintainerAlerter;
use App\Services\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('weather:backfill {--limit=200 : Cap on rows handled per run}')]
#[Description('Fetch weather for activities with stored coords that have none yet (transient Open-Meteo misses) or were sampled at the start hour only.')]
class BackfillActivityWeatherCommand extends Command
{
    public function handle(OpenMeteoClient $weather, MaintainerAlerter $alerter): int
    {
        $limit = (int) $this->option('limit');

        $query = ActivityDetail::query()
            ->whereNull('weather_temp_max_c')
            ->whereNotNull('start_lat')
            ->whereNotNull('start_lng')
            ->whereNotNull('start_date_local')
            ->where('weather_attempts', '<', ActivityDetail::MAX_BACKFILL_ATTEMPTS)
            ->whereHas('activity', fn ($query) => $query->withStubs()->whereHas('user', fn ($user) => $user->notDemo()))
            ->orderByRaw('weather_temp_c is not null')
            ->orderByRaw('weather_attempted_at is not null')
            ->orderBy('weather_attempted_at')
            ->orderBy('id')
            ->limit($limit);

        $filled = 0;
        foreach ($query->cursor() as $detail) {
            if ($this->backfill($weather, $detail)) {
                $filled++;
            }
        }

        $this->info(sprintf(
            'Backfilled weather for %d activity detail(s) (limit %d).',
            $filled,
            $limit,
        ));

        $alerter->persistentGap('weather:backfill', 'weather', $this->persistentGaps());

        return self::SUCCESS;
    }

    private function persistentGaps(): int
    {
        return ActivityDetail::query()
            ->whereNull('weather_temp_c')
            ->whereNotNull('start_lat')
            ->whereNotNull('start_lng')
            ->whereNotNull('start_date_local')
            ->whereBetween('created_at', [
                now()->subDays(ActivityDetail::PERSISTENT_GAP_MAX_DAYS),
                now()->subHours(ActivityDetail::PERSISTENT_GAP_HOURS),
            ])
            ->whereHas('activity.user', fn ($query) => $query->notDemo())
            ->count();
    }

    private function backfill(OpenMeteoClient $weather, ActivityDetail $detail): bool
    {
        if ($detail->start_date_local === null) {
            return false;
        }

        $snapshot = $weather->fetchForActivity(
            (float) $detail->start_lat,
            (float) $detail->start_lng,
            CarbonImmutable::instance($detail->start_date_local),
            $detail->elapsed_time ?? 0,
        );

        if (!$snapshot instanceof WeatherSnapshot) {
            $detail->update([
                'weather_attempts' => $detail->weather_attempts + 1,
                'weather_attempted_at' => now(),
            ]);

            return false;
        }

        $detail->update($snapshot->toActivityDetailAttributes());

        return true;
    }
}
