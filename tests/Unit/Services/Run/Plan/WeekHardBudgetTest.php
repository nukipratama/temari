<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\WeekHardBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function bookedDay(string $date, int $hardMinutes, array $attributes = []): PlannedSession
{
    return PlannedSession::factory()->make(['date' => $date, 'prescribed_hard_minutes' => $hardMinutes, ...$attributes]);
}

it('books only the hard minutes still ahead: planned, not skipped, not yet run', function (): void {
    $sessions = collect([
        bookedDay('2026-08-11', 20),
        bookedDay('2026-08-14', 15),
        bookedDay('2026-08-12', 10),
        bookedDay('2026-08-13', 0),
        bookedDay('2026-08-15', 10, ['skipped' => true]),
        bookedDay('2026-08-16', 10, ['status' => PlannedSessionStatus::Done]),
        bookedDay('2026-08-17', 10),
    ]);

    expect(WeekHardBudget::bookings($sessions, Carbon::parse('2026-08-12'), ['2026-08-17']))
        ->toBe(['2026-08-12' => 10, '2026-08-14' => 15]);
});

it('weighs the week\'s spent and booked minutes against its locked cap', function (): void {
    Carbon::setTestNow('2026-08-12 09:00:00');
    $user = User::factory()->create();
    $adaptation = PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-08-10', 'hard_cap_minutes' => 60]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-08-11 07:00:00',
        'elapsed_time' => 3000,
        'stream_summary' => ['time_in_zone_min' => ['Z2' => 35, 'Z3' => 15]],
    ]);

    $budget = app(WeekHardBudget::class)->of($user, Carbon::today(), $adaptation, collect([bookedDay('2026-08-14', 20)]), []);

    expect($budget)->toBe(['cap_minutes' => 60, 'spent_minutes' => 15, 'booked_minutes' => 20, 'left_minutes' => 25])
        ->and(app(WeekHardBudget::class)->of($user, Carbon::today(), null, collect(), []))->toBeNull();
});
