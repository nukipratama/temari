<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('advances the PR-seen marker to the latest PR for the authenticated user', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $user = User::factory()->create(['last_seen_pr_ledger_at' => null]);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    PersonalRecord::factory()->for($user)->create([
        'activity_id' => $activity->id,
        'set_at' => Carbon::today()->subHour(),
    ]);

    $this->actingAs($user)->postJson('/api/pr-ledger/seen')
        ->assertSuccessful()
        ->assertJson(['ok' => true]);

    expect($user->refresh()->last_seen_pr_ledger_at?->toDateTimeString())
        ->toBe(Carbon::today()->subHour()->toDateTimeString());

    Carbon::setTestNow();
});

it('is a no-op when the user has no personal records', function (): void {
    $user = User::factory()->create(['last_seen_pr_ledger_at' => null]);

    $this->actingAs($user)->postJson('/api/pr-ledger/seen')
        ->assertSuccessful();

    expect($user->refresh()->last_seen_pr_ledger_at)->toBeNull();
});

it('only advances the marker from the authenticated user\'s own PRs', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $user = User::factory()->create(['last_seen_pr_ledger_at' => null]);
    $other = User::factory()->create();
    // A newer PR belonging to someone else must not leak into this user's marker.
    PersonalRecord::factory()->for($other)->create(['set_at' => Carbon::today()]);
    PersonalRecord::factory()->for($user)->create(['set_at' => Carbon::today()->subDay()]);

    $this->actingAs($user)->postJson('/api/pr-ledger/seen')->assertSuccessful();

    expect($user->refresh()->last_seen_pr_ledger_at?->toDateTimeString())
        ->toBe(Carbon::today()->subDay()->toDateTimeString());

    Carbon::setTestNow();
});

it('requires authentication', function (): void {
    $this->postJson('/api/pr-ledger/seen')->assertUnauthorized();
});
