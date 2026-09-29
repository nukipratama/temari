<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\RunQuestion;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts ids to integers, the status to its enum and the claim time to a date', function (): void {
    $row = RunQuestion::factory()->create(['claimed_at' => '2026-09-26 12:00:00', 'follow_ups' => ['what about km 5?']]);

    expect($row->refresh()->user_id)->toBeInt()
        ->and($row->activity_id)->toBeInt()
        ->and($row->follow_ups)->toBe(['what about km 5?'])
        ->and($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->claimed_at)->toBeInstanceOf(Carbon::class);
});

it('belongs to the asking user and the run it is about', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $row = RunQuestion::factory()->create(['user_id' => $user->id, 'activity_id' => $activity->id]);

    expect($row->user->id)->toBe($user->id)
        ->and($row->activity->id)->toBe($activity->id);
});

it('reads one run thread oldest-first and leaves other runs out', function (): void {
    $user = User::factory()->create();
    $mine = Activity::factory()->for($user)->create();
    $other = Activity::factory()->for($user)->create();

    $first = RunQuestion::factory()->create(['user_id' => $user->id, 'activity_id' => $mine->id, 'question' => 'first?']);
    $second = RunQuestion::factory()->create(['user_id' => $user->id, 'activity_id' => $mine->id, 'question' => 'second?']);
    RunQuestion::factory()->create(['user_id' => $user->id, 'activity_id' => $other->id, 'question' => 'elsewhere?']);

    expect(RunQuestion::query()->forActivity($mine->id)->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

it('lists the questions already asked about one run, oldest first', function (): void {
    $user = User::factory()->create();
    $mine = Activity::factory()->for($user)->create();
    $other = Activity::factory()->for($user)->create();
    RunQuestion::factory()->create(['user_id' => $user->id, 'activity_id' => $mine->id, 'question' => 'first?']);
    RunQuestion::factory()->create(['user_id' => $user->id, 'activity_id' => $other->id, 'question' => 'elsewhere?']);
    RunQuestion::factory()->create(['user_id' => $user->id, 'activity_id' => $mine->id, 'question' => 'second?']);

    expect(RunQuestion::askedAbout($mine->id))->toBe(['first?', 'second?']);
});

it('goes with the run it was asked about', function (): void {
    $row = RunQuestion::factory()->create();

    Activity::query()->whereKey($row->activity_id)->delete();

    expect(RunQuestion::query()->whereKey($row->id)->exists())->toBeFalse();
});
