<?php

declare(strict_types=1);

use App\Actions\AI\RecentlyActiveUsers;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-05-18 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function athleteWhoRan(int $daysAgo, bool $demo = false): User
{
    $user = $demo ? User::factory()->demo()->create() : User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create(['analyzed_at' => Carbon::now()]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()->subDays($daysAgo)]);

    return $user;
}

it('returns the athletes who ran inside the active window', function (): void {
    $recent = athleteWhoRan(2);
    $onTheEdge = athleteWhoRan(RecentlyActiveUsers::ACTIVE_WINDOW_DAYS);
    $dormant = athleteWhoRan(RecentlyActiveUsers::ACTIVE_WINDOW_DAYS + 1);

    $users = app(RecentlyActiveUsers::class)();

    expect($users->pluck('id')->all())
        ->toContain($recent->id, $onTheEdge->id)
        ->not->toContain($dormant->id);
});

it('excludes the demo account', function (): void {
    $demo = athleteWhoRan(1, demo: true);

    expect(app(RecentlyActiveUsers::class)()->pluck('id')->all())->not->toContain($demo->id);
});

it('returns each athlete once however many runs they logged', function (): void {
    $user = athleteWhoRan(1);
    $second = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($second)->create(['start_date_local' => Carbon::today()]);

    expect(app(RecentlyActiveUsers::class)()->where('id', $user->id))->toHaveCount(1);
});
