<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\User;
use App\Policies\ActivityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows the owner to view their activity', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();

    expect((new ActivityPolicy())->view($user, $activity))->toBeTrue();
});

it('denies a non-owner', function (): void {
    $activity = Activity::factory()->for(User::factory())->create();
    $other = User::factory()->create();

    expect((new ActivityPolicy())->view($other, $activity))->toBeFalse();
});

it('is resolved by Gate via can()', function (): void {
    $owner = User::factory()->create();
    $activity = Activity::factory()->for($owner)->create();

    expect($owner->can('view', $activity))->toBeTrue()
        ->and(User::factory()->create()->can('view', $activity))->toBeFalse();
});
