<?php

declare(strict_types=1);

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('forUser scopes to records owned by the user', function (): void {
    $user = User::factory()->create();
    $mine = PersonalRecord::factory()->for($user)->create();
    PersonalRecord::factory()->create(); // another user

    expect(PersonalRecord::query()->forUser($user->id)->pluck('id')->all())->toBe([$mine->id]);
});

it('casts value_sec to float and set_at to Carbon', function (): void {
    $pr = PersonalRecord::factory()->make([
        'user_id' => 1,
        'value_sec' => '341.82',
        'set_at' => '2026-04-09 00:00:00',
    ]);

    expect($pr->value_sec)->toBeFloat()->toEqualWithDelta(341.82, 0.01)
        ->and($pr->set_at)->toBeInstanceOf(Carbon::class);
});

it('serializes set_at as the verbatim wall-clock, not a UTC-shifted instant', function (): void {
    // set_at is copied from the run's start_date_local (a location wall-clock) and
    // rendered through the frontend's naive parsers, so it must serialize unshifted.
    $pr = new PersonalRecord(['set_at' => '2026-01-01 06:20:30']);

    expect($pr->toArray()['set_at'])->toBe('2026-01-01T06:20:30');
});

it('belongs to a user and optionally to an activity', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $pr = PersonalRecord::factory()->for($user)->forActivity($activity)->create();

    expect($pr->user->is($user))->toBeTrue()
        ->and($pr->activity)->toBeInstanceOf(Activity::class)
        ->and($pr->activity->is($activity))->toBeTrue();
});

it('nullifies activity_id when the activity is deleted (PR outlives source run)', function (): void {
    $activity = Activity::factory()->create();
    $pr = PersonalRecord::factory()->forActivity($activity)->create();

    Activity::query()->whereKey($activity->id)->delete();

    expect($pr->fresh()->activity_id)->toBeNull();
});

it('enforces one PR per (user_id, category)', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km']);

    expect(fn () => PersonalRecord::factory()->for($user)->create(['category' => '5km']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same category under different users', function (): void {
    PersonalRecord::factory()->create(['category' => '5km']);
    $second = PersonalRecord::factory()->create(['category' => '5km']);

    expect($second->category)->toBe(PrCategory::Km5);
});
