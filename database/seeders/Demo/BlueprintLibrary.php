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

    /** @var list<RunBlueprint>|null memoized so the fixture is built once per seed */
    private ?array $scriptedCache = null;

    /**
     * @return list<RunBlueprint>
     */
    public function all(): array
    {
        return [...$this->scripted(), ...$this->fillers()];
    }

    private function loc(int $index): DemoLocation
    {
        return DemoLocation::library()[$index];
    }

    /**
     * Order = day-descending (oldest first → today last) for natural CTL ramp.
     * Locations are spread across the curated Indonesian spots; the
     * half-marathon at D-136 is the all-time-longest run (→ Legendary card).
     *
     * @return list<RunBlueprint>
     */
    private function scripted(): array
    {
        return $this->scriptedCache ??= [
            // --- Older base: building from couch to a first half marathon ---
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(178)->setTime(6, 30),
                distanceM: 4_000,
                targetPaceSecPerKm: 450,
                hrProfile: HrProfile::Z2Steady,
                name: 'Lari pelan pertama',
                tags: ['baseline'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(171)->setTime(6, 30),
                distanceM: 5_000,
                targetPaceSecPerKm: 440,
                hrProfile: HrProfile::Z2Steady,
                name: 'First proper 5K',
                tags: ['first_5k', 'baseline'],
                location: $this->loc(1),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(157)->setTime(6, 0),
                distanceM: 12_000,
                targetPaceSecPerKm: 480,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 80,
                name: 'Sunday LSD',
                tags: ['long_slow_distance'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(150)->setTime(6, 0),
                distanceM: 5_500,
                targetPaceSecPerKm: 425,
                hrProfile: HrProfile::Z2Steady,
                name: 'Pagi di Bandung',
                tags: ['travel'],
                location: $this->loc(3),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(143)->setTime(6, 30),
                distanceM: 8_000,
                targetPaceSecPerKm: 410,
                hrProfile: HrProfile::Tempo,
                name: 'Tempo pertama',
                tags: ['tempo'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(136)->setTime(5, 30),
                distanceM: 21_300,
                targetPaceSecPerKm: 495,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 140,
                name: 'Half marathon perdana',
                tags: ['half_marathon', 'big_volume', 'all_time_longest'],
                location: $this->loc(0),
            ),
            // --- Half-marathon progression: four more HMs after the perdana,
            // each a notch faster, so /rekor's featured progression chart draws a
            // real improving line toward the Sub-2:45 goal instead of one point.
            // All stay under the perdana's 21.3km so it keeps `all_time_longest`.
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(118)->setTime(5, 40),
                distanceM: 21_150,
                targetPaceSecPerKm: 488,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 120,
                name: 'Half marathon kedua',
                tags: ['half_marathon', 'big_volume'],
                location: $this->loc(5),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(90)->setTime(5, 45),
                distanceM: 21_120,
                targetPaceSecPerKm: 483,
                hrProfile: HrProfile::NegSplit,
                elevationGainM: 90,
                name: 'Half marathon pagi Bali',
                tags: ['half_marathon', 'big_volume', 'travel'],
                location: $this->loc(6),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(55)->setTime(5, 30),
                distanceM: 21_097,
                targetPaceSecPerKm: 478,
                hrProfile: HrProfile::Tempo,
                elevationGainM: 70,
                name: 'Half marathon race-pace',
                tags: ['half_marathon', 'big_volume', 'negative_split'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(12)->setTime(5, 30),
                distanceM: 21_100,
                targetPaceSecPerKm: 476,
                hrProfile: HrProfile::NegSplit,
                elevationGainM: 80,
                name: 'Half marathon PR',
                tags: ['half_marathon', 'big_volume', 'pembalik_keadaan'],
                location: $this->loc(0),
            ),
            // --- Full-marathon progression: three FMs across the window so the
            // /rekor chart can plot a marathon line too. All fall AFTER the D-136
            // half-marathon perdana, so that HM keeps the first distance-milestone
            // Legendary and the first marathon earns a second one. The first
            // marathon is the longest (its lone marathon Legendary); the rest
            // improve toward a PR. Low-variance LsdDrift keeps the first longest.
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(105)->setTime(5, 0),
                distanceM: 42_600,
                targetPaceSecPerKm: 519,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 260,
                name: 'Marathon perdana',
                tags: ['marathon', 'big_volume', 'all_time_longest'],
                location: $this->loc(4),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(65)->setTime(5, 0),
                distanceM: 42_450,
                targetPaceSecPerKm: 498,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 220,
                name: 'Marathon kedua',
                tags: ['marathon', 'big_volume', 'travel'],
                location: $this->loc(3),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(32)->setTime(5, 0),
                distanceM: 42_350,
                targetPaceSecPerKm: 481,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 180,
                name: 'Marathon PR',
                tags: ['marathon', 'big_volume', 'pembalik_keadaan'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(129)->setTime(6, 0),
                distanceM: 5_000,
                targetPaceSecPerKm: 400,
                hrProfile: HrProfile::NegSplit,
                name: 'Negative split Surabaya',
                tags: ['negative_split', 'travel'],
                location: $this->loc(4),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(122)->setTime(11, 0),
                distanceM: 10_000,
                targetPaceSecPerKm: 405,
                hrProfile: HrProfile::LsdDrift,
                weatherTempC: 32,
                weatherHumidityPct: 88,
                name: 'Midday tropical 10K',
                tags: ['hari_panas'],
                location: $this->loc(2),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(115)->setTime(5, 10),
                distanceM: 9_000,
                targetPaceSecPerKm: 385,
                hrProfile: HrProfile::Intervals,
                cadenceSpm: 178,
                name: 'Dawn 6×800m',
                tags: ['anak_pagi', 'intervals'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(108)->setTime(17, 30),
                distanceM: 8_000,
                targetPaceSecPerKm: 430,
                hrProfile: HrProfile::Z2Steady,
                weatherTempC: 25,
                weatherHumidityPct: 95,
                weatherRainDetected: true,
                name: 'Hujan deras 8K',
                tags: ['pejuang_hujan'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(94)->setTime(6, 0),
                distanceM: 6_000,
                targetPaceSecPerKm: 430,
                hrProfile: HrProfile::Z2Steady,
                name: 'Lari pagi di Sanur',
                tags: ['travel'],
                location: $this->loc(6),
            ),
            // --- Recent block: sharpening toward a 10K PR ---
            // Past You anchor for the D-0 run below.
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(87)->setTime(6, 45),
                distanceM: 5_200,
                targetPaceSecPerKm: 410,
                hrProfile: HrProfile::Z2Steady,
                name: 'Morning 5K loop',
                tags: ['past_you_anchor'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(74)->setTime(6, 0),
                distanceM: 14_000,
                targetPaceSecPerKm: 470,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 80,
                name: 'Sunday LSD 14K',
                tags: ['long_slow_distance'],
                location: $this->loc(5),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(58)->setTime(11, 0),
                distanceM: 10_000,
                targetPaceSecPerKm: 400,
                hrProfile: HrProfile::LsdDrift,
                weatherTempC: 32,
                weatherHumidityPct: 88,
                name: 'Midday tropical 10K',
                tags: ['hari_panas'],
                location: $this->loc(7),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(51)->setTime(17, 30),
                distanceM: 8_000,
                targetPaceSecPerKm: 425,
                hrProfile: HrProfile::Z2Steady,
                weatherTempC: 25,
                weatherHumidityPct: 95,
                weatherRainDetected: true,
                name: 'Hujan deras 8K',
                tags: ['pejuang_hujan'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(44)->setTime(5, 10),
                distanceM: 9_000,
                targetPaceSecPerKm: 375,
                hrProfile: HrProfile::Intervals,
                cadenceSpm: 178,
                name: 'Dawn 6×800m',
                tags: ['anak_pagi', 'intervals'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(37)->setTime(6, 30),
                distanceM: 10_000,
                targetPaceSecPerKm: 375,
                hrProfile: HrProfile::NegSplit,
                name: 'Negative split tempo',
                tags: ['negative_split', 'pembalik_keadaan'],
                location: $this->loc(1),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(30)->setTime(17, 0),
                distanceM: 4_000,
                targetPaceSecPerKm: 510,
                hrProfile: HrProfile::Z2Steady,
                cadenceSpm: 168,
                name: 'Recovery jog',
                tags: ['recovery'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(23)->setTime(6, 0),
                distanceM: 18_000,
                targetPaceSecPerKm: 465,
                hrProfile: HrProfile::LsdDrift,
                elevationGainM: 120,
                name: 'Sunday long 18K',
                tags: ['long_slow_distance', 'big_volume'],
                location: $this->loc(0),
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
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(9)->setTime(6, 30),
                distanceM: 8_000,
                targetPaceSecPerKm: 360,
                hrProfile: HrProfile::Tempo,
                name: 'Fresh tempo 8K',
                tags: ['fresh'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(4)->setTime(6, 0),
                distanceM: 10_000,
                targetPaceSecPerKm: 348,
                hrProfile: HrProfile::Tempo,
                cadenceSpm: 176,
                weatherTempC: 26,
                name: '10K race-pace effort',
                tags: ['10k_pr_attempt'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->subDays(1)->setTime(17, 30),
                distanceM: 5_000,
                targetPaceSecPerKm: 470,
                hrProfile: HrProfile::Z2Steady,
                name: 'Yesterday shakeout',
                tags: ['recovery'],
                location: $this->loc(0),
            ),
            new RunBlueprint(
                startsAt: Carbon::today()->setTime(6, 30),
                distanceM: 5_200,
                targetPaceSecPerKm: 395,
                hrProfile: HrProfile::NegSplit,
                name: 'Pagi negative split',
                tags: ['negative_split', 'past_you_today'],
                location: $this->loc(0),
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
                location: $this->loc(0),
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

        for ($d = 182; $d >= 2; $d--) {
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
        $profile = $this->rollProfile($rng);
        [$distance, $pace] = $this->distanceAndPaceFor($profile, $rng);

        $startHour = $rng->getInt(0, 99) < 70 ? 6 : 17;
        $minute = $rng->getInt(0, 50);
        $temp = $rng->getInt(24, 31);
        $rain = $rng->getInt(0, 99) < 15;
        $humidity = $rain ? $rng->getInt(85, 95) : $rng->getInt(65, 85);

        $dataRoll = $rng->getInt(0, 99);
        $hasGps = $dataRoll >= self::TREADMILL_BUCKET_HI;
        $hasSensors = $dataRoll < self::TREADMILL_BUCKET_HI || $dataRoll >= self::PHONE_ONLY_BUCKET_HI;

        $cadenceBase = $profile === HrProfile::Intervals ? 176 : 168;
        $library = DemoLocation::library();

        return new RunBlueprint(
            startsAt: $date->copy()->setTime($startHour, $minute),
            distanceM: $distance,
            targetPaceSecPerKm: $pace,
            hrProfile: $profile,
            cadenceSpm: $cadenceBase + $rng->getInt(0, 6),
            elevationGainM: $hasGps ? $rng->getInt(20, 70) : 0,
            weatherTempC: $temp,
            weatherHumidityPct: $humidity,
            weatherRainDetected: $rain,
            name: $this->fillerName($date, $profile),
            tags: ['filler', $profile->value],
            hasGps: $hasGps,
            hasHrSensor: $hasSensors,
            hasCadenceSensor: $hasSensors,
            location: $hasGps ? $library[$rng->getInt(0, count($library) - 1)] : null,
        );
    }

    /**
     * Weighted toward easy aerobic base (how real training distributes), with
     * a minority of quality sessions for variety.
     */
    private function rollProfile(Randomizer $rng): HrProfile
    {
        $roll = $rng->getInt(0, 99);

        return match (true) {
            $roll < 55 => HrProfile::Z2Steady,
            $roll < 70 => HrProfile::Tempo,
            $roll < 82 => HrProfile::LsdDrift,
            $roll < 92 => HrProfile::Intervals,
            default => HrProfile::NegSplit,
        };
    }

    /**
     * @return array{int, int} distance in metres, target pace in sec/km
     */
    private function distanceAndPaceFor(HrProfile $profile, Randomizer $rng): array
    {
        return match ($profile) {
            HrProfile::Tempo => [$rng->getInt(60, 100) * 100, $rng->getInt(380, 410)],
            HrProfile::LsdDrift => [$rng->getInt(120, 200) * 100, $rng->getInt(460, 510)],
            HrProfile::Intervals => [$rng->getInt(60, 90) * 100, $rng->getInt(360, 400)],
            HrProfile::NegSplit => [$rng->getInt(50, 100) * 100, $rng->getInt(390, 420)],
            HrProfile::Z2Steady => [$rng->getInt(40, 120) * 100, $rng->getInt(420, 510)],
        };
    }

    private function fillerName(Carbon $date, HrProfile $profile): string
    {
        $name = match ($profile) {
            HrProfile::Tempo => 'Tempo sore',
            HrProfile::LsdDrift => 'Long run santai',
            HrProfile::Intervals => 'Interval pagi',
            HrProfile::NegSplit => 'Progresif',
            HrProfile::Z2Steady => 'Easy aerobic',
        };

        return $name.' '.$date->translatedFormat('d M');
    }
}
