<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\RunQuestion;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts ids to integers and the status to its enum', function (): void {
    $row = RunQuestion::factory()->create();

    expect($row->refresh()->user_id)->toBeInt()
        ->and($row->activity_id)->toBeInt()
        ->and($row->status)->toBe(AnalysisStatus::Queued);
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

it('goes with the run it was asked about', function (): void {
    $row = RunQuestion::factory()->create();

    Activity::query()->whereKey($row->activity_id)->delete();

    expect(RunQuestion::query()->whereKey($row->id)->exists())->toBeFalse();
});
