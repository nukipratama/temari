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

function athleteLastSeen(?int $daysAgo, bool $demo = false): User
{
    $factory = $demo ? User::factory()->demo() : User::factory();

    return $factory->create([
        'last_seen_at' => $daysAgo === null ? null : Carbon::today()->subDays($daysAgo),
    ]);
}

it('returns the athletes who opened the app inside the active window', function (): void {
    $recent = athleteLastSeen(2);
    $onTheEdge = athleteLastSeen(RecentlyActiveUsers::ACTIVE_WINDOW_DAYS);
    $dormant = athleteLastSeen(RecentlyActiveUsers::ACTIVE_WINDOW_DAYS + 1);

    $users = app(RecentlyActiveUsers::class)();

    expect($users->pluck('id')->all())
        ->toContain($recent->id, $onTheEdge->id)
        ->not->toContain($dormant->id);
});

it('excludes an athlete who has never opened the app', function (): void {
    $neverSeen = athleteLastSeen(null);

    expect(app(RecentlyActiveUsers::class)()->pluck('id')->all())->not->toContain($neverSeen->id);
});

it('excludes an athlete who ran but has not opened the app', function (): void {
    $user = athleteLastSeen(RecentlyActiveUsers::ACTIVE_WINDOW_DAYS + 1);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);

    expect(app(RecentlyActiveUsers::class)()->pluck('id')->all())->not->toContain($user->id);
});

it('excludes the demo account', function (): void {
    $demo = athleteLastSeen(1, demo: true);

    expect(app(RecentlyActiveUsers::class)()->pluck('id')->all())->not->toContain($demo->id);
});

it('lists the active ids', function (): void {
    $active = athleteLastSeen(1);
    $dormant = athleteLastSeen(30);

    expect(app(RecentlyActiveUsers::class)->ids())
        ->toContain($active->id)
        ->not->toContain($dormant->id);
});
