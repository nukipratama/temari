<?php

declare(strict_types=1);

use App\Jobs\Run\RollWeeklySnapshotsForwardJob;
use App\Models\User;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('is unique per athlete and rolls their dirty weeks forward', function (): void {
    $user = User::factory()->create();
    $aggregator = Mockery::mock(WeeklyAggregator::class);
    $aggregator->shouldReceive('rollForwardDirty')->once()->with(Mockery::on(fn (User $u): bool => $u->id === $user->id));

    $job = new RollWeeklySnapshotsForwardJob($user->id);
    $job->handle($aggregator);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $user->id)
        ->and(Queue::connection('sync')->getJobTries($job))->toBe(3)
        ->and(Queue::connection('sync')->getJobBackoff($job))->toBe('30,120');
});

it('never rolls the demo forward', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $aggregator = Mockery::mock(WeeklyAggregator::class);
    $aggregator->shouldNotReceive('rollForwardDirty');

    new RollWeeklySnapshotsForwardJob($demo->id)->handle($aggregator);
});
