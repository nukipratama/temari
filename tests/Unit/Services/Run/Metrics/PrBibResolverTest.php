<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RecordStamp;
use App\Models\User;
use App\Services\Run\Metrics\PrBibResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->resolver = new PrBibResolver();
});

it('stamps a run that currently holds a tracked distance PR, animating since nothing has claimed it yet', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['distance' => 10_000]);
    PersonalRecord::factory()->forActivity($activity)->create([
        'category' => '10km',
        'value_sec' => 2400.0,
    ]);

    $bib = $this->resolver->resolve($activity, $detail);

    expect($bib)->toBe(['label' => '10K', 'value_sec' => 2400.0, 'distance_m' => null, 'record_key' => '10km', 'animate' => true]);
});

it('never writes a RecordStamp row: resolving is a plain read', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['distance' => 10_000]);
    PersonalRecord::factory()->forActivity($activity)->create(['category' => '10km']);

    $this->resolver->resolve($activity, $detail);
    $this->resolver->resolve($activity, $detail);

    expect(RecordStamp::query()->count())->toBe(0);
});

it('stops animating once something else has claimed the stamp for that record', function (): void {
    $user = User::factory()->create();
    $decoy = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($decoy)->create(['distance' => 30_000]);
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['distance' => 10_000]);
    PersonalRecord::factory()->forActivity($activity)->create(['category' => '10km']);

    $first = $this->resolver->resolve($activity, $detail);
    RecordStamp::query()->create(['user_id' => $user->id, 'record_key' => '10km', 'seen_at' => now()]);
    $second = $this->resolver->resolve($activity, $detail);

    expect($first['animate'])->toBeTrue()
        ->and($second)->not->toBeNull()
        ->and($second['label'])->toBe('10K')
        ->and($second['animate'])->toBeFalse();
});

it("does not let one user's claimed stamp suppress another user's animation", function (): void {
    $userA = User::factory()->create();
    $decoyA = Activity::factory()->for($userA)->create();
    ActivityDetail::factory()->for($decoyA)->create(['distance' => 30_000]);
    $activityA = Activity::factory()->for($userA)->create();
    $detailA = ActivityDetail::factory()->for($activityA)->create(['distance' => 10_000]);
    PersonalRecord::factory()->forActivity($activityA)->create(['category' => '10km']);
    RecordStamp::query()->create(['user_id' => $userA->id, 'record_key' => '10km', 'seen_at' => now()]);

    $userB = User::factory()->create();
    $decoyB = Activity::factory()->for($userB)->create();
    ActivityDetail::factory()->for($decoyB)->create(['distance' => 30_000]);
    $activityB = Activity::factory()->for($userB)->create();
    $detailB = ActivityDetail::factory()->for($activityB)->create(['distance' => 10_000]);
    PersonalRecord::factory()->forActivity($activityB)->create(['category' => '10km']);

    expect($this->resolver->resolve($activityB, $detailB)['animate'])->toBeTrue();
});

it('does not stamp a run that no longer holds the record', function (): void {
    $user = User::factory()->create();
    $decoy = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($decoy)->create(['distance' => 30_000]);
    $olderActivity = Activity::factory()->for($user)->create();
    $olderDetail = ActivityDetail::factory()->for($olderActivity)->create(['distance' => 10_000]);
    $newerActivity = Activity::factory()->for($user)->create();
    PersonalRecord::factory()->forActivity($newerActivity)->create(['category' => '10km']);

    expect($this->resolver->resolve($olderActivity, $olderDetail))->toBeNull();
});

it('stamps a new longest run', function (): void {
    $user = User::factory()->create();
    $shorter = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($shorter)->create(['distance' => 10_000]);
    $longest = Activity::factory()->for($user)->create();
    $longestDetail = ActivityDetail::factory()->for($longest)->create(['distance' => 21_100]);

    $bib = $this->resolver->resolve($longest, $longestDetail);

    expect($bib)->toBe(['label' => 'Longest Run', 'value_sec' => null, 'distance_m' => 21_100.0, 'record_key' => 'longest_run', 'animate' => true]);
});

it('does not stamp a run shorter than the account\'s longest', function (): void {
    $user = User::factory()->create();
    $longest = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($longest)->create(['distance' => 21_100]);
    $shorter = Activity::factory()->for($user)->create();
    $shorterDetail = ActivityDetail::factory()->for($shorter)->create(['distance' => 10_000]);

    expect($this->resolver->resolve($shorter, $shorterDetail))->toBeNull();
});

it('prefers the rarer record when a run holds more than one, leaving the other unseen', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['distance' => 42_195]);
    PersonalRecord::factory()->forActivity($activity)->create(['category' => 'marathon']);
    PersonalRecord::factory()->forActivity($activity)->create(['category' => '10km']);

    $bib = $this->resolver->resolve($activity, $detail);

    expect($bib['label'])->toBe('FM')
        ->and(RecordStamp::query()->where('user_id', $user->id)->where('record_key', '10km')->exists())->toBeFalse();
});

it('ignores best-effort pace categories, which are not tracked bib records', function (): void {
    $user = User::factory()->create();
    $decoy = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($decoy)->create(['distance' => 30_000]);
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['distance' => 10_000]);
    PersonalRecord::factory()->forActivity($activity)->create(['category' => 'best_5min']);

    expect($this->resolver->resolve($activity, $detail))->toBeNull();
});

it('ignores the 1km fastest-km category', function (): void {
    $user = User::factory()->create();
    $decoy = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($decoy)->create(['distance' => 30_000]);
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['distance' => 1_000]);
    PersonalRecord::factory()->forActivity($activity)->create(['category' => '1km']);

    expect($this->resolver->resolve($activity, $detail))->toBeNull();
});
