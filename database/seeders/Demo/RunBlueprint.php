<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Carbon;

final readonly class RunBlueprint
{
    /**
     * @param  list<string>  $tags  human-readable annotations for debugging the seeded data
     * @param  list<int>|null  $lapDistancesM  manual lap lengths in metres; null means the watch's 1 km auto-split
     */
    public function __construct(
        public Carbon $startsAt,
        public int $distanceM,
        public int $targetPaceSecPerKm,
        public HrProfile $hrProfile,
        public int $cadenceSpm = 170,
        public int $elevationGainM = 30,
        public ?int $weatherTempC = 27,
        public ?int $weatherHumidityPct = 75,
        public bool $weatherRainDetected = false,
        public ?int $weatherWindSpeedKmh = 11,
        public ?string $name = null,
        public array $tags = [],
        public bool $hasGps = true,
        public bool $hasHrSensor = true,
        public bool $hasCadenceSensor = true,
        public ?DemoLocation $location = null,
        public ?array $lapDistancesM = null,
        public ?int $workoutType = null,
    ) {
    }

    public function movingTimeSec(): int
    {
        return (int) round(($this->distanceM / 1000) * $this->targetPaceSecPerKm);
    }

    /** Warms a degree every half hour up to 3°, so only longer demo runs show a peak. */
    public function weatherTempMaxC(): ?int
    {
        return $this->weatherTempC === null ? null : $this->weatherTempC + min(3, intdiv($this->movingTimeSec(), 1800));
    }

    /** Magnus approximation, the formula Open-Meteo derives dew point with. */
    public function weatherDewPointC(): ?int
    {
        if ($this->weatherTempC === null || $this->weatherHumidityPct === null) {
            return null;
        }

        $gamma = log($this->weatherHumidityPct / 100) + 17.27 * $this->weatherTempC / (237.7 + $this->weatherTempC);

        return (int) round(237.7 * $gamma / (17.27 - $gamma));
    }

    /** Steadman's apparent temperature, the formula behind Open-Meteo's `apparent_temperature`. */
    public function weatherApparentTempC(): ?int
    {
        if ($this->weatherTempC === null || $this->weatherHumidityPct === null) {
            return null;
        }

        $vapourPressureHpa = $this->weatherHumidityPct / 100 * 6.105 * exp(17.27 * $this->weatherTempC / (237.7 + $this->weatherTempC));
        $windMs = ($this->weatherWindSpeedKmh ?? 0) / 3.6;

        return (int) round($this->weatherTempC + 0.33 * $vapourPressureHpa - 0.70 * $windMs - 4.00);
    }

    public function seed(): int
    {
        return (int) abs(crc32($this->startsAt->toDateString().':'.$this->distanceM.':'.$this->hrProfile->value));
    }
}
