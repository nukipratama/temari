<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Story\BadgeEvaluator;
use App\Services\Run\Story\CardContext;
use Illuminate\Support\Carbon;

/** @param array<string, mixed> $attributes */
function badgeDetail(array $attributes = []): ActivityDetail
{
    return new ActivityDetail($attributes + [
        'distance' => 5_000.0,
        'moving_time' => 1_800,
        'elapsed_time' => 1_800,
        'start_date_local' => Carbon::parse('2026-05-12 10:00:00'),
    ]);
}

function badgeContext(int $streak = 0, bool $firstRun = false, ?int $maxHr = null): CardContext
{
    return new CardContext($firstRun, false, false, $streak, $maxHr);
}

/**
 * @param  array<string, mixed>  $summary
 * @return list<string>
 */
function badgesFor(ActivityDetail $detail, array $summary = [], ?CardContext $context = null): array
{
    return app(BadgeEvaluator::class)->evaluate(
        $detail,
        StreamSummary::fromArray($summary),
        $context ?? badgeContext(),
    );
}

it('awards no badges for a plain mid-morning run', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 25])))->toBe([]);
});

it('awards heat_tamer at 31C and above', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 31])))->toContain('heat_tamer');
});

it('awards rain_warrior when rain was detected', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 25, 'weather_rain_detected' => true])))
        ->toContain('rain_warrior');
});

it('awards headwind at 20kmh and above', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 25, 'weather_wind_speed_kmh' => 20])))
        ->toContain('headwind');
});

it('awards early_bird before 6am', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 25, 'start_date_local' => Carbon::parse('2026-05-12 05:30:00')]);

    expect(badgesFor($detail))->toContain('early_bird');
});

it('awards night_owl before 5am or from 9pm', function (): void {
    $night = badgeDetail(['weather_temp_c' => 25, 'start_date_local' => Carbon::parse('2026-05-12 21:00:00')]);

    expect(badgesFor($night))->toContain('night_owl');
});

it('awards long_slow_distance on a 12km-plus hour-plus easy run', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 25, 'distance' => 12_000.0, 'elapsed_time' => 3_600]);

    expect(badgesFor($detail, ['time_in_zone_pct' => ['Z2' => 90, 'Z3' => 10]]))
        ->toContain('long_slow_distance');
});

it('awards negative_split when the summary reports one', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 25]), ['negative_split' => true]))
        ->toContain('negative_split');
});

it('awards held_back on a 10km-plus run under 10 percent hard zones', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 25, 'distance' => 10_000.0]);

    expect(badgesFor($detail, ['time_in_zone_pct' => ['Z2' => 95, 'Z3' => 5]]))
        ->toContain('held_back');
});

it('awards climber on total elevation gain', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 25, 'total_elevation_gain' => 200.0])))
        ->toContain('climber');
});

it('awards climber on a steep max grade without big total gain', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 25]), ['max_grade_pct' => 8.0]))
        ->toContain('climber');
});

it('awards first_timer when the context says this is the first run ever', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 25]), [], badgeContext(firstRun: true)))
        ->toContain('first_timer');
});

it('awards speedster under 5 minutes per km', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 25, 'distance' => 5_000.0, 'moving_time' => 1_200]);

    expect(badgesFor($detail))->toContain('speedster');
});

it('awards long_hauler from half-marathon distance', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 25, 'distance' => 21_097.5, 'elapsed_time' => 1_800]);

    expect(badgesFor($detail))->toContain('long_hauler');
});

it('awards z2_master above 80 percent Z2', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 25]), ['time_in_zone_pct' => ['Z2' => 81]]))
        ->toContain('z2_master');
});

it('awards cold_runner at or below 20C', function (): void {
    expect(badgesFor(badgeDetail(['weather_temp_c' => 20])))->toContain('cold_runner');
});

it('falls back to a pre-dawn window for cold_runner when no weather is stored', function (): void {
    $detail = badgeDetail(['start_date_local' => Carbon::parse('2026-05-12 04:30:00')]);

    expect(badgesFor($detail))->toContain('cold_runner');
});

it('does not award cold_runner on a warm pre-dawn run', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 28, 'start_date_local' => Carbon::parse('2026-05-12 04:30:00')]);

    expect(badgesFor($detail))->toContain('early_bird')->not->toContain('cold_runner');
});

it('awards all_out above 85 percent of the athlete max HR', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 25, 'average_heartrate' => 170.0]);

    expect(badgesFor($detail, [], badgeContext(maxHr: 190)))->toContain('all_out');
});

it('awards easy_miles below 78 percent of the athlete max HR', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 25, 'average_heartrate' => 143.0]);

    expect(badgesFor($detail, [], badgeContext(maxHr: 190)))
        ->toContain('easy_miles')
        ->not->toContain('all_out');
});

it('awards no effort badge when the context carries no athlete max HR', function (): void {
    $detail = badgeDetail(['weather_temp_c' => 25, 'average_heartrate' => 170.0]);

    expect(badgesFor($detail))->not->toContain('all_out')->not->toContain('easy_miles');
});

it('reads aerobic discipline off distance and hard-zone share', function (): void {
    $evaluator = app(BadgeEvaluator::class);
    $disciplined = StreamSummary::fromArray(['time_in_zone_pct' => ['Z3' => 5]]);
    $hard = StreamSummary::fromArray(['time_in_zone_pct' => ['Z3' => 20]]);

    expect($evaluator->isAerobicDiscipline(badgeDetail(['distance' => 10_000.0]), $disciplined))->toBeTrue()
        ->and($evaluator->isAerobicDiscipline(badgeDetail(['distance' => 10_000.0]), $hard))->toBeFalse()
        ->and($evaluator->isAerobicDiscipline(badgeDetail(['distance' => 9_999.0]), $disciplined))->toBeFalse();
});
