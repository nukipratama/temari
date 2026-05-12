<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Returns the demo run blueprint set: ~14 curated runs that exercise every
 * UI state (rarities, badges, moods, Past You match), plus ~60 aerobic
 * filler runs scattered across the same ~90-day window so the dashboard
 * fitness chart and /runs pagination look like a real history.
 *
 * Today (`Carbon::today()`) is treated as D-0; D-N means N days ago.
 */
class BlueprintLibrary
{
    /** Deterministic seed for filler-run jitter. */
    private const int FILLER_SEED = 4242;

    /** % of non-scripted days in the window that get a filler run. */
    private const int FILLER_RATE_PCT = 65;

    /** Filler RNG buckets controlling the data-shape mix. Disjoint ranges
     *  on a 0–99 roll: a filler is either treadmill OR phone-only OR full. */
    private const int TREADMILL_BUCKET_HI = 8;   // [0,8)  → ~8% treadmill (no GPS)

    private const int PHONE_ONLY_BUCKET_HI = 13; // [8,13) → ~5% phone-only (no HR/cadence)

    /**
     * @return list<RunBlueprint>
     */
    public function all(): array
    {
        return [...$this->scripted(), ...$this->fillers()];
    }

    /**
     * The 14 hand-tuned runs from the plan. Order = day-descending in the
     * timeline (oldest first → today last) for natural CTL ramp.
     *
     * @return list<RunBlueprint>
     */
    private function scripted(): array
    {
        return [
            // #1 D-87 — first 5K, sets baseline 5km PR
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(87)->setTime(6, 30),
                distanceM: 5_000,
                targetPaceSecPerKm: 420, // 7:00/km
                hrProfile: HrProfile::Z2Steady,
                name: 'First proper 5K',
                tags: ['first_5k', 'baseline'],
            ),
            // #2 D-74 — long Z2 LSD
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(74)->setTime(6, 0),
                distanceM: 14_000,
                targetPaceSecPerKm: 480, // 8:00/km — easy
                hrProfile: HrProfile::Z2Steady,
                elevationGainM: 80,
                name: 'Sunday LSD',
                tags: ['long_slow_distance'],
            ),
            // #3 D-65 — 5K easy comparable to #1 (Past You target for #14)
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(65)->setTime(6, 45),
                distanceM: 5_200,
                targetPaceSecPerKm: 410, // 6:50/km — slightly faster than #1
                hrProfile: HrProfile::Z2Steady,
                name: 'Morning 5K loop',
                tags: ['past_you_anchor'],
            ),
            // #4 D-58 — 10K in tropical heat
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(58)->setTime(11, 0),
                distanceM: 10_000,
                targetPaceSecPerKm: 410, // 6:50/km, expect drift
                hrProfile: HrProfile::LsdDrift,
                weatherTempC: 32,
                weatherHumidityPct: 88,
                name: 'Midday tropical 10K',
                tags: ['hari_panas'],
            ),
            // #5 D-51 — 8K thunderstorm
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(51)->setTime(17, 30),
                distanceM: 8_000,
                targetPaceSecPerKm: 430,
                hrProfile: HrProfile::Z2Steady,
                weatherTempC: 25,
                weatherHumidityPct: 95,
                weatherRainDetected: true,
                name: 'Hujan deras 8K',
                tags: ['pejuang_hujan'],
            ),
            // #6 D-44 — dawn intervals
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(44)->setTime(5, 10),
                distanceM: 9_000,
                targetPaceSecPerKm: 380, // 6:20/km avg with hard reps
                hrProfile: HrProfile::Intervals,
                cadenceSpm: 178,
                name: 'Dawn 6×800m',
                tags: ['anak_pagi', 'intervals'],
            ),
            // #7 D-37 — negative-split 10K
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(37)->setTime(6, 30),
                distanceM: 10_000,
                targetPaceSecPerKm: 380, // 6:20/km avg
                hrProfile: HrProfile::NegSplit,
                name: 'Negative split tempo',
                tags: ['negative_split', 'pembalik_keadaan'],
            ),
            // #8 D-30 — recovery
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(30)->setTime(17, 0),
                distanceM: 4_000,
                targetPaceSecPerKm: 510, // 8:30/km — proper recovery
                hrProfile: HrProfile::Z2Steady,
                cadenceSpm: 168,
                name: 'Recovery jog',
                tags: ['recovery'],
            ),
            // #9 D-23 — second long run
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(23)->setTime(6, 0),
                distanceM: 18_000,
                targetPaceSecPerKm: 470,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 120,
                name: 'Sunday long 18K',
                tags: ['long_slow_distance', 'big_volume'],
            ),
            // #10 D-16 — fatigued tempo, high drift
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(16)->setTime(6, 30),
                distanceM: 8_000,
                targetPaceSecPerKm: 400,
                hrProfile: HrProfile::LsdDrift,
                weatherTempC: 31,
                weatherHumidityPct: 85,
                name: 'Heavy legs tempo',
                tags: ['fatigued', 'high_drift'],
            ),
            // #11 D-9 — fresh tempo rebound
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(9)->setTime(6, 30),
                distanceM: 8_000,
                targetPaceSecPerKm: 360,
                hrProfile: HrProfile::Tempo,
                name: 'Fresh tempo 8K',
                tags: ['fresh'],
            ),
            // #12 D-4 — 10K PR run
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(4)->setTime(6, 0),
                distanceM: 10_000,
                targetPaceSecPerKm: 350, // 5:50/km — beats #4 cleanly
                hrProfile: HrProfile::Tempo,
                cadenceSpm: 176,
                weatherTempC: 26,
                name: '10K race-pace effort',
                tags: ['10k_pr_attempt'],
            ),
            // #13 D-1 — yesterday recovery
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(1)->setTime(17, 30),
                distanceM: 5_000,
                targetPaceSecPerKm: 470,
                hrProfile: HrProfile::Z2Steady,
                name: 'Yesterday shakeout',
                tags: ['recovery'],
            ),
            // #14 D-0 — today, negative-split 5K
            new RunBlueprint(
                startsAt: Carbon::today()->setTime(6, 30),
                distanceM: 5_200,
                targetPaceSecPerKm: 395, // faster than #3 → past-you flex
                hrProfile: HrProfile::NegSplit,
                name: 'Pagi negative split',
                tags: ['negative_split', 'past_you_today'],
            ),
            // #15 D-68 — treadmill session, GPS off (hujan deras di luar).
            // Exercises: no summary_polyline, no latlng stream; HR + cadence
            // tetep ada karena pakai watch.
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(68)->setTime(20, 0),
                distanceM: 8_000,
                targetPaceSecPerKm: 420,
                hrProfile: HrProfile::Tempo,
                cadenceSpm: 174,
                elevationGainM: 0,
                weatherTempC: 24,
                weatherHumidityPct: 55,
                weatherRainDetected: false,
                name: 'Treadmill 8K (hujan deras)',
                tags: ['no_gps', 'treadmill'],
                hasGps: false,
            ),
            // #16 D-40 — phone-only run, no HR / no cadence sensors.
            // Exercises: has_heartrate=false, no zone data, RunCardFactory
            // falls back to biasa rarity (no zone data → fails jarang gate).
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(40)->setTime(6, 0),
                distanceM: 5_500,
                targetPaceSecPerKm: 440,
                hrProfile: HrProfile::Z2Steady,
                name: 'Lari pagi tanpa jam',
                tags: ['no_hr', 'no_cadence', 'phone_only'],
                hasHrSensor: false,
                hasCadenceSensor: false,
            ),
        ];
    }

    /**
     * Aerobic filler runs scattered across the timeline so the dashboard
     * chart looks like a real history. Skips days that already host a
     * scripted run (above) to keep things believable.
     *
     * @return list<RunBlueprint>
     */
    private function fillers(): array
    {
        $scriptedDates = array_map(
            fn (RunBlueprint $b): string => $b->startsAt->toDateString(),
            $this->scripted(),
        );

        $rng = new Randomizer(new Mt19937(self::FILLER_SEED));
        $blueprints = [];
        $today = Carbon::today();

        // Walk D-90 → D-2; offer a filler about every 1–2 days on average.
        for ($d = 90; $d >= 2; $d--) {
            $date = $today->copy()->subDays($d);
            if (in_array($date->toDateString(), $scriptedDates, true)) {
                continue;
            }
            if ($rng->getInt(0, 99) >= self::FILLER_RATE_PCT) {
                continue;
            }
            $blueprints[] = $this->makeFiller($date, $rng);
        }

        return $blueprints;
    }

    private function makeFiller(Carbon $date, Randomizer $rng): RunBlueprint
    {
        $distance = $rng->getInt(40, 120) * 100; // 4 000–12 000 m
        $pace = $rng->getInt(420, 510); // 7:00–8:30
        $startHour = $rng->getInt(0, 99) < 70 ? 6 : 17; // mostly mornings
        $minute = $rng->getInt(0, 50);
        $temp = $rng->getInt(24, 31);
        $rain = $rng->getInt(0, 99) < 15;
        $humidity = $rain ? $rng->getInt(85, 95) : $rng->getInt(65, 85);

        $dataRoll = $rng->getInt(0, 99);
        $hasGps = $dataRoll >= self::TREADMILL_BUCKET_HI;
        $hasSensors = $dataRoll < self::TREADMILL_BUCKET_HI || $dataRoll >= self::PHONE_ONLY_BUCKET_HI;

        return new RunBlueprint(
            startsAt: $date->copy()->setTime($startHour, $minute),
            distanceM: $distance,
            targetPaceSecPerKm: $pace,
            hrProfile: HrProfile::Z2Steady,
            cadenceSpm: 168 + $rng->getInt(0, 6),
            elevationGainM: $hasGps ? $rng->getInt(20, 70) : 0,
            weatherTempC: $temp,
            weatherHumidityPct: $humidity,
            weatherRainDetected: $rain,
            name: $this->fillerName($date, $rng),
            tags: ['filler'],
            hasGps: $hasGps,
            hasHrSensor: $hasSensors,
            hasCadenceSensor: $hasSensors,
        );
    }

    private function fillerName(Carbon $date, Randomizer $rng): string
    {
        $names = [
            'Easy aerobic',
            'Morning loop',
            'Evening base',
            'Komplek run',
            'Pelan-pelan',
            'Z2 base',
            'Senayan loop',
            'Sore santai',
        ];

        return $names[$rng->getInt(0, count($names) - 1)].' '.$date->translatedFormat('d M');
    }
}
