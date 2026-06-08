<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts timestamps and counters', function (): void {
    $activity = Activity::factory()->analyzed()->create([
        'detail_fail_count' => 2,
        'strava_external_id' => '9876543210',
    ]);

    expect($activity->fetched_at)->toBeInstanceOf(Carbon::class)
        ->and($activity->analyzed_at)->toBeInstanceOf(Carbon::class)
        ->and($activity->detail_fail_count)->toBe(2)
        ->and($activity->strava_external_id)->toBe(9876543210);
});

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();

    expect($activity->user)->toBeInstanceOf(User::class)
        ->and($activity->user->is($user))->toBeTrue();
});

it('has one detail, one stream, and one run card', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create();
    ActivityStream::factory()->for($activity)->create();
    RunCard::factory()->for($activity)->create();

    expect($activity->detail)->toBeInstanceOf(ActivityDetail::class)
        ->and($activity->stream)->toBeInstanceOf(ActivityStream::class)
        ->and($activity->runCard)->toBeInstanceOf(RunCard::class);
});

it('has many personal records and story lines', function (): void {
    $activity = Activity::factory()->create();
    // Pin categories so factory randomness doesn't collide on the unique index.
    PersonalRecord::factory()->forActivity($activity)->create(['category' => '5km']);
    PersonalRecord::factory()->forActivity($activity)->create(['category' => '10km']);
    StoryLine::factory()->for($activity)->count(1)->create([
        'user_id' => $activity->user_id,
    ]);

    expect($activity->personalRecords)->toHaveCount(2)
        ->and($activity->storyLines)->toHaveCount(1);
});

it('enforces unique (user_id, strava_external_id)', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->create(['strava_external_id' => 12345]);

    expect(fn () => Activity::factory()->for($user)->create(['strava_external_id' => 12345]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same strava_external_id under different users', function (): void {
    Activity::factory()->create(['strava_external_id' => 12345]);
    $second = Activity::factory()->create(['strava_external_id' => 12345]);

    expect($second->strava_external_id)->toBe(12345);
});
