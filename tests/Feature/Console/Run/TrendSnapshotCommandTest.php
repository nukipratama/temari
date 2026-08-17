<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('writes a snapshot row for every real user', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);

    $this->artisan('trend:snapshot-daily')
        ->expectsOutputToContain("Wrote today's trend snapshot for 1 users.")
        ->assertSuccessful();

    expect(TrendDailySnapshot::query()->where('user_id', $user->id)->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('writes a row even for a user with no run today, so a rest week still grows history', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();

    $this->artisan('trend:snapshot-daily')->assertSuccessful();

    $snap = TrendDailySnapshot::query()->where('user_id', $user->id)->sole();
    expect($snap->vdot)->toBeNull()
        ->and($snap->pace_variability_sec)->toBeNull();

    Carbon::setTestNow();
});

it('writes a snapshot row for the demo user too, since this is free local computation, not a billing call', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $demo = User::factory()->demo()->create();

    $this->artisan('trend:snapshot-daily')
        ->expectsOutputToContain("Wrote today's trend snapshot for 1 users.")
        ->assertSuccessful();

    expect(TrendDailySnapshot::query()->where('user_id', $demo->id)->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('is idempotent when run twice the same day', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();

    $this->artisan('trend:snapshot-daily')->assertSuccessful();
    $this->artisan('trend:snapshot-daily')->assertSuccessful();

    expect(TrendDailySnapshot::query()->where('user_id', $user->id)->count())->toBe(1);

    Carbon::setTestNow();
});
