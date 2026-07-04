<?php

declare(strict_types=1);

namespace App\Console\Commands\Weather;

use App\Models\ActivityDetail;
use App\Services\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('weather:backfill {--limit=200 : Cap on rows handled per run}')]
#[Description('Re-fetch weather for activities with stored coords but a null weather_temp_c (transient Open-Meteo misses).')]
class BackfillActivityWeatherCommand extends Command
{
    public function handle(OpenMeteoClient $weather): int
    {
        $limit = (int) $this->option('limit');

        $query = ActivityDetail::query()
            ->whereNull('weather_temp_c')
            ->whereNotNull('start_lat')
            ->whereNotNull('start_lng')
            ->whereNotNull('start_date_local')
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

        return self::SUCCESS;
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
        );

        if ($snapshot === null) {
            return false;
        }

        $detail->update($snapshot->toActivityDetailAttributes());

        return true;
    }
}
