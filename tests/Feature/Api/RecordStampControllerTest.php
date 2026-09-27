<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RecordStamp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('claims the stamp for a record the user holds', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    PersonalRecord::factory()->forActivity($activity)->create(['category' => '10km']);

    $this->actingAs($user)
        ->postJson(route('api.record-stamps.store'), ['record_key' => '10km'])
        ->assertNoContent();

    expect(RecordStamp::query()->where('user_id', $user->id)->where('record_key', '10km')->exists())->toBeTrue();
});

it('claims the longest_run stamp for a user who has logged a run', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['distance' => 21_100]);

    $this->actingAs($user)
        ->postJson(route('api.record-stamps.store'), ['record_key' => 'longest_run'])
        ->assertNoContent();

    expect(RecordStamp::query()->where('user_id', $user->id)->where('record_key', 'longest_run')->exists())->toBeTrue();
});

it('is idempotent: replaying the same claim never duplicates the row', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    PersonalRecord::factory()->forActivity($activity)->create(['category' => '10km']);

    $this->actingAs($user)->postJson(route('api.record-stamps.store'), ['record_key' => '10km'])->assertNoContent();
    $this->actingAs($user)->postJson(route('api.record-stamps.store'), ['record_key' => '10km'])->assertNoContent();

    expect(RecordStamp::query()->where('user_id', $user->id)->where('record_key', '10km')->count())->toBe(1);
});

it("rejects a record_key the user doesn't actually hold", function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.record-stamps.store'), ['record_key' => '10km'])
        ->assertStatus(422);

    expect(RecordStamp::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects a record_key outside the tracked set', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.record-stamps.store'), ['record_key' => 'best_5min'])
        ->assertInvalid(['record_key']);
});

it("leaves another user's stamp untouched", function (): void {
    $userA = User::factory()->create();
    $activityA = Activity::factory()->for($userA)->create();
    PersonalRecord::factory()->forActivity($activityA)->create(['category' => '10km']);

    $userB = User::factory()->create();
    $activityB = Activity::factory()->for($userB)->create();
    PersonalRecord::factory()->forActivity($activityB)->create(['category' => '10km']);

    $this->actingAs($userA)->postJson(route('api.record-stamps.store'), ['record_key' => '10km'])->assertNoContent();

    expect(RecordStamp::query()->where('user_id', $userB->id)->where('record_key', '10km')->exists())->toBeFalse();

    $this->actingAs($userB)
        ->postJson(route('api.record-stamps.store'), ['record_key' => '10km'])
        ->assertNoContent();

    expect(RecordStamp::query()->where('user_id', $userB->id)->where('record_key', '10km')->exists())->toBeTrue();
});

it('rejects a guest', function (): void {
    $this->postJson(route('api.record-stamps.store'), ['record_key' => '10km'])->assertUnauthorized();
});
