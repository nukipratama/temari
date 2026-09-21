<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveRecentLongestRunAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the longest analyzed run inside the trailing window', function (): void {
    $user = User::factory()->create();
    foreach ([[8_000, 4], [12_000, 20], [18_000, 31]] as [$distanceM, $daysAgo]) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'distance' => $distanceM,
            'start_date_local' => Carbon::today()->subDays($daysAgo),
        ]);
    }

    expect((new ResolveRecentLongestRunAction())($user->id, Carbon::today(), 30))->toBe(12_000.0);
});

it('reads identical questions once', function (): void {
    $user = User::factory()->create();
    $resolve = new ResolveRecentLongestRunAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $resolve($user->id, Carbon::today(), 30);
    $resolve($user->id, Carbon::today(), 30);

    expect($queries)->toBe(1);
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveRecentLongestRunAction::class))->toBe(app(ResolveRecentLongestRunAction::class));
});
