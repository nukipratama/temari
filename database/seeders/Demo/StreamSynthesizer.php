<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Determinism: seeded by `RunBlueprint::seed()` so the same blueprint
 * always produces the same streams.
 */
class StreamSynthesizer
{
    /**
     * @return array<string, array{data: list<int|float|array{float, float}>}>
     */
    public function build(RunBlueprint $blueprint): array
    {
        $duration = $blueprint->movingTimeSec();
        if ($duration <= 0) {
            $duration = 1;
        }
        $avgSpeed = $blueprint->distanceM / $duration;
        $rng = new Randomizer(new Mt19937($blueprint->seed()));
        $location = $blueprint->location ?? DemoLocation::default();

        $time = [];
        /** @var list<float> $rawVelocity unrounded; the scale factor and rescale both work off this series so rounding never re-introduces drift */
        $rawVelocity = [];
        $velocity = [];
        $heartrate = [];
        $cadence = [];
        $altitude = [];
        $latlng = [];
        $distance = [];
        $acc = 0.0;

        for ($t = 0; $t <= $duration; $t++) {
            $progress = $t / $duration;
            $time[] = $t;

            $v = $this->velocityAt($blueprint, $progress, $avgSpeed, $rng);
            $rawVelocity[] = $v;
            $velocity[] = round($v, 3);
            $acc += $v;
            $distance[] = round($acc, 2);

            if ($blueprint->hasHrSensor) {
                $heartrate[] = $this->hrAt($blueprint, $progress, $rng);
            }
            if ($blueprint->hasCadenceSensor) {
                $cadence[] = $this->cadenceAt($blueprint, $progress, $v, $avgSpeed, $rng);
            }
            $altitude[] = $this->altitudeAt($blueprint, $progress);
            if ($blueprint->hasGps) {
                $latlng[] = $this->latLngAt($location, $progress);
            }
        }

        // Scale velocities so accumulated distance matches the blueprint
        // exactly, compensating for drift from per-step jitter. The scale
        // factor is computed from the unrounded $acc, so the rescale must walk
        // the same unrounded series; rounding happens only at output. Walking
        // the rounded series here would re-introduce the drift the scale was
        // meant to cancel.
        if ($acc > 0.0 && $acc !== (float) $blueprint->distanceM) {
            $scale = (float) $blueprint->distanceM / $acc;
            $velocity = [];
            $distance = [];
            $acc = 0.0;
            foreach ($rawVelocity as $v) {
                $scaled = $v * $scale;
                $acc += $scaled;
                $velocity[] = round($scaled, 3);
                $distance[] = round($acc, 2);
            }
        }

        $streams = [
            'time' => ['data' => $time],
            'velocity_smooth' => ['data' => $velocity],
            'altitude' => ['data' => $altitude],
            'distance' => ['data' => $distance],
        ];
        if ($blueprint->hasHrSensor) {
            $streams['heartrate'] = ['data' => $heartrate];
        }
        if ($blueprint->hasCadenceSensor) {
            $streams['cadence'] = ['data' => $cadence];
        }
        if ($blueprint->hasGps) {
            $streams['latlng'] = ['data' => $latlng];
        }

        return $streams;
    }

    private function velocityAt(RunBlueprint $b, float $progress, float $avg, Randomizer $rng): float
    {
        $multiplier = $b->hrProfile->velocityMultiplier($progress, $this->intervalWork($progress));

        return max(0.5, $avg * $multiplier * $rng->getFloat(0.96, 1.04));
    }

    private function hrAt(RunBlueprint $b, float $progress, Randomizer $rng): int
    {
        $base = $b->hrProfile->hrBase($progress, $this->intervalWork($progress));

        return (int) round($base + $rng->getFloat(-3.0, 3.0));
    }

    private function cadenceAt(RunBlueprint $b, float $progress, float $currentVelocity, float $avgVelocity, Randomizer $rng): int
    {
        $velocityRatio = $avgVelocity > 0 ? $currentVelocity / $avgVelocity : 1.0;
        $velocityOffset = ($velocityRatio - 1.0) * 18.0;
        $drift = $b->hrProfile->cadenceDrift($progress);

        $spm = $b->cadenceSpm + $velocityOffset + $drift + $rng->getFloat(-2.0, 2.0);
        $leg = (int) round($spm / 2);

        return max(75, min(95, $leg));
    }

    private function altitudeAt(RunBlueprint $b, float $progress): float
    {
        return round(10.0 + $b->elevationGainM * (0.5 + 0.5 * sin($progress * M_PI * 2)), 2);
    }

    /**
     * @return array{float, float}
     */
    private function latLngAt(DemoLocation $location, float $progress): array
    {
        $angle = $progress * 2 * M_PI;

        return [
            round($location->lat + 0.008 * sin($angle), 6),
            round($location->lng + 0.008 * cos($angle), 6),
        ];
    }

    // 6-segment cycle: odd thirds are the work bouts.
    private function intervalWork(float $progress): bool
    {
        return ((int) floor($progress * 6)) % 2 === 1;
    }

}
