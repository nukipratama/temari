<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Carbon;

/**
 * Synthetic-run spec the demo seeder feeds to the real ingest pipeline.
 *
 * The seeder skips Strava entirely. Instead, each blueprint is materialised
 * into Activity + ActivityDetail + ActivityStream rows, then the real
 * StreamAnalysis / PersonalRecords / RunCardFactory / Temari services run
 * on top — so the cards/PRs/story lines on screen are the actual product
 * output for the synthesised input.
 *
 * `hrProfile` shapes both velocity and heart-rate over the run:
 *
 *   z2_steady   flat aerobic effort, hr in low Z2
 *   tempo       sustained Z3/low-Z4
 *   intervals   alternating Z2 / Z4 segments
 *   lsd_drift   long Z2 with hr drifting up over the second half
 *   neg_split   first half ~5% slower, second half 5–10% faster, hr rises
 */
final class RunBlueprint
{
    /**
     * `hasGps`, `hasHrSensor`, `hasCadenceSensor` produce intentionally
     * incomplete data so the seed exercises real-Strava heterogeneity:
     * treadmill runs (no GPS, no polyline), phone-only runs (no HR / no
     * cadence sensors). The platform doesn't classify these — it just
     * renders whatever fields are present.
     *
     * @param  list<string>  $tags  human-readable annotations for debugging the seeded data
     */
    public function __construct(
        public readonly Carbon $startsAt,
        public readonly int $distanceM,
        public readonly int $targetPaceSecPerKm,
        public readonly string $hrProfile,
        public readonly int $cadenceSpm = 170,
        public readonly int $elevationGainM = 30,
        public readonly ?int $weatherTempC = 27,
        public readonly ?int $weatherHumidityPct = 75,
        public readonly bool $weatherRainDetected = false,
        public readonly ?string $name = null,
        public readonly array $tags = [],
        public readonly bool $hasGps = true,
        public readonly bool $hasHrSensor = true,
        public readonly bool $hasCadenceSensor = true,
    ) {
    }

    public function movingTimeSec(): int
    {
        return (int) round(($this->distanceM / 1000) * $this->targetPaceSecPerKm);
    }

    /** Stable seed for deterministic stream synthesis. */
    public function seed(): int
    {
        return (int) abs(crc32($this->startsAt->toDateString().':'.$this->distanceM.':'.$this->hrProfile));
    }
}
