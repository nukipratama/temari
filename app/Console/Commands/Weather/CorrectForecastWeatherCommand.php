<?php

declare(strict_types=1);

namespace App\Console\Commands\Weather;

use App\Models\ActivityDetail;
use App\Services\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('weather:correct-forecast {--limit=200 : Cap on rows handled per run}')]
#[Description('Re-fetch weather from the archive endpoint for rows still sourced from a forecast, once the archive is reliable (a week+ old).')]
class CorrectForecastWeatherCommand extends Command
{
    /**
     * The ERA5T archive lags real-time by ~5 days, and the forecast endpoint
     * reliably covers ~7 days of past, so a week is the clean handover point: a
     * run is corrected exactly when it leaves the forecast window and the
     * observed archive has caught up. Before that, a re-fetch would just hit the
     * same forecast-derived value.
     */
    private const int MIN_AGE_DAYS = 7;

    public function handle(OpenMeteoClient $weather): int
    {
        $limit = (int) $this->option('limit');
        $cutoff = CarbonImmutable::now()->subDays(self::MIN_AGE_DAYS);

        $query = ActivityDetail::query()
            ->where('weather_rain_is_forecast', true)
            ->whereNotNull('start_lat')
            ->whereNotNull('start_lng')
            ->where('start_date_local', '<', $cutoff)
            ->orderBy('start_date_local')
            ->limit($limit);

        $corrected = 0;
        foreach ($query->cursor() as $detail) {
            if ($this->correct($weather, $detail)) {
                $corrected++;
            }
        }

        $this->info(sprintf(
            'Corrected forecast-sourced weather for %d activity detail(s) (limit %d).',
            $corrected,
            $limit,
        ));

        return self::SUCCESS;
    }

    /**
     * Only the weather columns are touched here. RunCard badges (PejuangHujan,
     * HariPanas, LawanAngin, ...) are derived once at unlock time and are never
     * recomputed retroactively: stripping an earned badge because the archive
     * later disagrees with the forecast would revoke an unlocked accessory,
     * which is worse UX than a slightly-stale badge.
     */
    private function correct(OpenMeteoClient $weather, ActivityDetail $detail): bool
    {
        if ($detail->start_date_local === null) {
            return false;
        }

        $snapshot = $weather->fetchArchive(
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
