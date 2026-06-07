<?php

declare(strict_types=1);

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

it('casts value_sec to float and set_at to Carbon', function (): void {
    $pr = PersonalRecord::factory()->create([
        'value_sec' => '341.82',
        'set_at' => '2026-04-09 00:00:00',
    ]);

    expect($pr->value_sec)->toBeFloat()->toEqualWithDelta(341.82, 0.01)
        ->and($pr->set_at)->toBeInstanceOf(Carbon::class);
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
