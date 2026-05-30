<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\CalendarBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @return array{gridStart: Carbon, gridEnd: Carbon, monthStart: Carbon, monthEnd: Carbon}
 */
function mayGrid(): array
{
    $monthStart = Carbon::create(2026, 5, 1)->startOfMonth();
    $monthEnd = $monthStart->copy()->endOfMonth();

    return [
        'gridStart' => $monthStart->copy()->startOfWeek(Carbon::MONDAY),
        'gridEnd' => $monthEnd->copy()->endOfWeek(Carbon::SUNDAY),
        'monthStart' => $monthStart,
        'monthEnd' => $monthEnd,
    ];
}

/**
 * @return array{date: string, day: int, is_current_month: bool, is_today: bool, distance_km: float|null, pace_sec_per_km: float|null, avg_hr: int|null, trimp: float|null, mood: string|null, activity_id: int|null}|null
 */
function cellOn(array $cells, string $date): ?array
{
    return collect($cells)->firstWhere('date', $date);
}

it('emits whole Mon-Sun weeks padded around the month', function (): void {
    $user = User::factory()->create();
    $g = mayGrid();

    $cells = (new CalendarBuilder())->buildCells($user, $g['gridStart'], $g['gridEnd'], $g['monthStart'], $g['monthEnd']);

    expect(count($cells) % 7)->toBe(0)
        ->and(cellOn($cells, '2026-05-15')['is_current_month'])->toBeTrue();
});

it('aggregates multiple runs on the same day into one cell with weighted HR', function (): void {
    $user = User::factory()->create();
    $g = mayGrid();

    foreach ([[3_000, 1_080, 140], [4_000, 1_440, 160]] as [$dist, $mt, $hr]) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::create(2026, 5, 15),
            'distance' => $dist,
            'moving_time' => $mt,
            'average_heartrate' => $hr,
            'trimp_edwards' => 20.0,
        ]);
    }

    $cells = (new CalendarBuilder())->buildCells($user, $g['gridStart'], $g['gridEnd'], $g['monthStart'], $g['monthEnd']);
    $cell = cellOn($cells, '2026-05-15');

    // Weighted HR = (140*1080 + 160*1440) / (1080+1440) = 351600 / 2520 ≈ 153.
    $expectedHr = (int) round((140 * 1080 + 160 * 1440) / (1080 + 1440));

    expect($cell['distance_km'])->toBe(7.0)
        ->and($cell['avg_hr'])->toBe($expectedHr)
        ->and($cell['trimp'])->toBe(40.0)
        ->and($cell['activity_id'])->toBeNull(); // multi-run days don't link
});

it('links a single-run day to its activity and attaches the mood', function (): void {
    $user = User::factory()->create();
    $g = mayGrid();

    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::create(2026, 5, 10),
        'distance' => 5_000,
        'moving_time' => 1_500,
        'average_heartrate' => 150,
    ]);
    StoryLine::factory()->for($activity)->create([
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => 'nyala',
    ]);

    $cells = (new CalendarBuilder())->buildCells($user, $g['gridStart'], $g['gridEnd'], $g['monthStart'], $g['monthEnd']);
    $cell = cellOn($cells, '2026-05-10');

    expect($cell['activity_id'])->toBe($activity->id)
        ->and($cell['mood'])->toBe('nyala')
        ->and($cell['pace_sec_per_km'])->toBe(300.0); // 1500s / 5km
});

it('emits null metrics for empty days', function (): void {
    $user = User::factory()->create();
    $g = mayGrid();

    $cells = (new CalendarBuilder())->buildCells($user, $g['gridStart'], $g['gridEnd'], $g['monthStart'], $g['monthEnd']);
    $cell = cellOn($cells, '2026-05-20');

    expect($cell['distance_km'])->toBeNull()
        ->and($cell['avg_hr'])->toBeNull()
        ->and($cell['mood'])->toBeNull()
        ->and($cell['activity_id'])->toBeNull();
});

it('ignores un-analyzed activities and other users', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $g = mayGrid();

    $pending = Activity::factory()->for($user)->create(['analyzed_at' => null]);
    ActivityDetail::factory()->for($pending)->create([
        'start_date_local' => Carbon::create(2026, 5, 12),
        'distance' => 5_000,
    ]);

    $otherActivity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($otherActivity)->create([
        'start_date_local' => Carbon::create(2026, 5, 12),
        'distance' => 8_000,
    ]);

    $cells = (new CalendarBuilder())->buildCells($user, $g['gridStart'], $g['gridEnd'], $g['monthStart'], $g['monthEnd']);

    expect(cellOn($cells, '2026-05-12')['distance_km'])->toBeNull();
});
