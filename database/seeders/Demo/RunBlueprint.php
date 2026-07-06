<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Carbon;

final readonly class RunBlueprint
{
    /**
     * @param  list<string>  $tags  human-readable annotations for debugging the seeded data
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
    ) {
    }

    public function movingTimeSec(): int
    {
        return (int) round(($this->distanceM / 1000) * $this->targetPaceSecPerKm);
    }

    public function seed(): int
    {
        return (int) abs(crc32($this->startsAt->toDateString().':'.$this->distanceM.':'.$this->hrProfile->value));
    }
}
