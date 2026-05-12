<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Random\Engine\Mt19937;
use Random\Randomizer;

use function count;

/**
 * Turns a `RunBlueprint` into Strava-shaped 1 Hz stream arrays. The output
 * keys match what `StreamAnalysis::compute()` reads:
 *
 *   time, heartrate, velocity_smooth, cadence, altitude, latlng, distance
 *
 * Each entry is wrapped as `['data' => [...]]` because Strava's per-stream
 * payload is a dict and `StreamAnalysis::data()` accepts either shape.
 *
 * Determinism: seeded by `RunBlueprint::seed()` so the same blueprint
 * always produces the same streams. Useful when re-running the seeder
 * to compare UI output across iterations.
 */
class StreamSynthesizer
{
    /** Jakarta-ish anchor for the canned route loops. */
    private const float ANCHOR_LAT = -6.2088;

    private const float ANCHOR_LNG = 106.8456;

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

        $time = [];
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
                $latlng[] = $this->latLngAt($progress);
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
        $multiplier = match ($b->hrProfile) {
            'neg_split' => $progress < 0.5 ? 0.96 : 1.07,
            'intervals' => $this->intervalCycle($progress) === 0 ? 0.70 : 1.30,
            'lsd_drift' => 1.04 - 0.08 * $progress,
            'tempo', 'z2_steady' => 1.0,
            default => 1.0,
        };

        return max(0.5, $avg * $multiplier * $rng->getFloat(0.96, 1.04));
    }

    private function hrAt(RunBlueprint $b, float $progress, Randomizer $rng): int
    {
        $base = match ($b->hrProfile) {
            'z2_steady' => 148.0,
            'tempo' => 164.0,
            'intervals' => $this->intervalCycle($progress) === 0 ? 138.0 : 174.0,
            'lsd_drift' => 145.0 + 22.0 * $progress,
            'neg_split' => $progress < 0.5 ? 150.0 : 162.0 + 12.0 * ($progress - 0.5) * 2,
            default => 148.0,
        };
        $noise = $rng->getFloat(-3.0, 3.0);

        return (int) round($base + $noise);
    }

    /**
     * Cadence varies with velocity (faster moments → higher cadence) plus a
     * profile-specific drift over the run. Real runners shift 6–10 spm
     * between easy and tempo paces and lose 2–4 spm to end-of-run fatigue.
     */
    private function cadenceAt(RunBlueprint $b, float $progress, float $currentVelocity, float $avgVelocity, Randomizer $rng): int
    {
        $velocityRatio = $avgVelocity > 0 ? $currentVelocity / $avgVelocity : 1.0;
        $velocityOffset = ($velocityRatio - 1.0) * 18.0;

        $drift = match ($b->hrProfile) {
            'lsd_drift' => -3.0 * $progress,
            'neg_split' => 4.0 * $progress,
            'intervals' => 0.0,
            default => -1.0 * $progress,
        };

        $spm = $b->cadenceSpm + $velocityOffset + $drift + $rng->getFloat(-2.0, 2.0);
        $leg = (int) round($spm / 2);

        return max(75, min(95, $leg));
    }

    private function altitudeAt(RunBlueprint $b, float $progress): float
    {
        // Sine wave so total ascent ≈ elevationGainM.
        return round(10.0 + $b->elevationGainM * (0.5 + 0.5 * sin($progress * M_PI * 2)), 2);
    }

    /**
     * @return array{float, float}
     */
    private function latLngAt(float $progress): array
    {
        $angle = $progress * 2 * M_PI;

        return [
            round(self::ANCHOR_LAT + 0.008 * sin($angle), 6),
            round(self::ANCHOR_LNG + 0.008 * cos($angle), 6),
        ];
    }

    /** Returns 0 (recovery) or 1 (work) for a 6-segment interval cycle. */
    private function intervalCycle(float $progress): int
    {
        return ((int) floor($progress * 6)) % 2;
    }

    /**
     * Sanity-only assertion to ensure stream arrays match in length when
     * tests want to verify the output shape.
     *
     * @param  array<string, mixed>  $streams
     */
    public static function lengthsMatch(array $streams): bool
    {
        $reference = null;
        foreach ($streams as $stream) {
            if (! is_array($stream) || ! isset($stream['data']) || ! is_array($stream['data'])) {
                return false;
            }
            $length = count($stream['data']);
            if ($reference === null) {
                $reference = $length;
            } elseif ($reference !== $length) {
                return false;
            }
        }

        return true;
    }
}
