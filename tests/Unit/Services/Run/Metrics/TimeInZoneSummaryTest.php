<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\TimeInZoneSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function zoneRun(User $user, string $date, array $minutes): void
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'stream_summary' => ['time_in_zone_min' => $minutes],
    ]);
}

it('sums zone minutes across the window and normalises them to percentages', function (): void {
    $user = User::factory()->create();
    $today = Carbon::parse('2026-06-01');

    zoneRun($user, '2026-05-25', ['Z1' => 10.0, 'Z2' => 20.0, 'Z3' => 5.0]);
    zoneRun($user, '2026-05-18', ['Z2' => 20.0, 'Z4' => 5.0, 'Z5' => 5.0]);

    expect(new TimeInZoneSummary()->forUser($user, $today))->toBe([
        'Z1' => 15.4,
        'Z2' => 61.5,
        'Z3' => 7.7,
        'Z4' => 7.7,
        'Z5' => 7.7,
    ]);
});

it('ignores runs older than the twelve-week window', function (): void {
    $user = User::factory()->create();
    $today = Carbon::parse('2026-06-01');

    zoneRun($user, '2026-05-30', ['Z2' => 30.0]);
    zoneRun($user, '2026-01-01', ['Z5' => 90.0]);

    expect(new TimeInZoneSummary()->forUser($user, $today))->toBe([
        'Z1' => 0.0,
        'Z2' => 100.0,
        'Z3' => 0.0,
        'Z4' => 0.0,
        'Z5' => 0.0,
    ]);
});

it('ignores another athlete\'s runs', function (): void {
    $user = User::factory()->create();
    $today = Carbon::parse('2026-06-01');

    zoneRun($user, '2026-05-30', ['Z3' => 40.0]);
    zoneRun(User::factory()->create(), '2026-05-30', ['Z1' => 400.0]);

    expect(new TimeInZoneSummary()->forUser($user, $today)['Z3'])->toBe(100.0);
});

it('returns nothing when no run in the window recorded heart rate', function (): void {
    $user = User::factory()->create();

    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-05-30 06:00:00'),
        'stream_summary' => ['best_5min_pace' => '4:30'],
    ]);

    expect(new TimeInZoneSummary()->forUser($user, Carbon::parse('2026-06-01')))->toBe([]);
});
