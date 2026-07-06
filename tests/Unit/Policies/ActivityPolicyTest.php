<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\User;
use App\Policies\ActivityPolicy;

it('allows the owner to view their activity', function (): void {
    $user = User::factory()->make(['id' => 1]);
    $activity = Activity::factory()->make(['user_id' => 1]);

    expect((new ActivityPolicy())->view($user, $activity))->toBeTrue();
});

it('denies a non-owner', function (): void {
    $activity = Activity::factory()->make(['user_id' => 1]);
    $other = User::factory()->make(['id' => 2]);

    expect((new ActivityPolicy())->view($other, $activity))->toBeFalse();
});

it('is resolved by Gate via can()', function (): void {
    $owner = User::factory()->make(['id' => 1]);
    $activity = Activity::factory()->make(['user_id' => 1]);

    expect($owner->can('view', $activity))->toBeTrue()
        ->and(User::factory()->make(['id' => 2])->can('view', $activity))->toBeFalse();
});
