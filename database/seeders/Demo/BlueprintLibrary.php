<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Today (`Carbon::today()`) is D-0; D-N means N days ago.
 */
class BlueprintLibrary
{
    private const int FILLER_SEED = 4242;

    private const int FILLER_RATE_PCT = 65;

    // Disjoint ranges on a 0–99 roll: filler is treadmill OR phone-only OR full.
    private const int TREADMILL_BUCKET_HI = 8;

    private const int PHONE_ONLY_BUCKET_HI = 13;

    /**
     * @return list<RunBlueprint>
     */
    public function all(): array
    {
        return [...$this->scripted(), ...$this->fillers()];
    }

    /**
     * Order = day-descending (oldest first → today last) for natural CTL ramp.
     *
     * @return list<RunBlueprint>
     */
    private function scripted(): array
    {
        return [
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(87)->setTime(6, 30),
                distanceM: 5_000,
                targetPaceSecPerKm: 420,
                hrProfile: HrProfile::Z2Steady,
                name: 'First proper 5K',
                tags: ['first_5k', 'baseline'],
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(74)->setTime(6, 0),
                distanceM: 14_000,
                targetPaceSecPerKm: 480,
                hrProfile: HrProfile::Z2Steady,
                elevationGainM: 80,
                name: 'Sunday LSD',
                tags: ['long_slow_distance'],
            ),
            // Past You anchor for the D-0 run below.
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(65)->setTime(6, 45),
                distanceM: 5_200,
                targetPaceSecPerKm: 410,
                hrProfile: HrProfile::Z2Steady,
                name: 'Morning 5K loop',
                tags: ['past_you_anchor'],
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(58)->setTime(11, 0),
                distanceM: 10_000,
                targetPaceSecPerKm: 410,
                hrProfile: HrProfile::LsdDrift,
                weatherTempC: 32,
                weatherHumidityPct: 88,
                name: 'Midday tropical 10K',
                tags: ['hari_panas'],
            ),
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
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(44)->setTime(5, 10),
                distanceM: 9_000,
                targetPaceSecPerKm: 380,
                hrProfile: HrProfile::Intervals,
                cadenceSpm: 178,
                name: 'Dawn 6×800m',
                tags: ['anak_pagi', 'intervals'],
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(37)->setTime(6, 30),
                distanceM: 10_000,
                targetPaceSecPerKm: 380,
                hrProfile: HrProfile::NegSplit,
                name: 'Negative split tempo',
                tags: ['negative_split', 'pembalik_keadaan'],
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(30)->setTime(17, 0),
                distanceM: 4_000,
                targetPaceSecPerKm: 510,
                hrProfile: HrProfile::Z2Steady,
                cadenceSpm: 168,
                name: 'Recovery jog',
                tags: ['recovery'],
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(23)->setTime(6, 0),
                distanceM: 18_000,
                targetPaceSecPerKm: 470,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 120,
                name: 'Sunday long 18K',
                tags: ['long_slow_distance', 'big_volume'],
            ),
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
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(9)->setTime(6, 30),
                distanceM: 8_000,
                targetPaceSecPerKm: 360,
                hrProfile: HrProfile::Tempo,
                name: 'Fresh tempo 8K',
                tags: ['fresh'],
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(4)->setTime(6, 0),
                distanceM: 10_000,
                targetPaceSecPerKm: 350,
                hrProfile: HrProfile::Tempo,
                cadenceSpm: 176,
                weatherTempC: 26,
                name: '10K race-pace effort',
                tags: ['10k_pr_attempt'],
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(1)->setTime(17, 30),
                distanceM: 5_000,
                targetPaceSecPerKm: 470,
                hrProfile: HrProfile::Z2Steady,
                name: 'Yesterday shakeout',
                tags: ['recovery'],
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->setTime(6, 30),
                distanceM: 5_200,
                targetPaceSecPerKm: 395,
                hrProfile: HrProfile::NegSplit,
                name: 'Pagi negative split',
                tags: ['negative_split', 'past_you_today'],
            ),
            // Treadmill: no summary_polyline / no latlng stream, HR+cadence intact.
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
            // Phone-only: has_heartrate=false → no zone data → common rarity fallback.
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
        $distance = $rng->getInt(40, 120) * 100;
        $pace = $rng->getInt(420, 510);
        $startHour = $rng->getInt(0, 99) < 70 ? 6 : 17;
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
