<?php

declare(strict_types=1);

use App\Jobs\Strava\ResyncActivityJob;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('requires authentication', function (): void {
    $activity = Activity::factory()->create();

    $this->post(route('aktivitas.resync', $activity))->assertRedirect(route('login'));
});

it('queues a resync for the owner and flashes a notice', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('aktivitas.resync', $activity))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(
        ResyncActivityJob::class,
        fn (ResyncActivityJob $job): bool => $job->activityId === $activity->id,
    );
});

it('404s when the activity belongs to another user', function (): void {
    Bus::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $activity = Activity::factory()->for($owner)->create();

    $this->actingAs($other)
        ->post(route('aktivitas.resync', $activity))
        ->assertNotFound();

    Bus::assertNotDispatched(ResyncActivityJob::class);
});

it('throttles rapid taps like the manual sync', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();

    $this->actingAs($user)->post(route('aktivitas.resync', $activity))->assertRedirect();
    $this->actingAs($user)->post(route('aktivitas.resync', $activity))->assertRedirect();
    $this->actingAs($user)->post(route('aktivitas.resync', $activity))->assertStatus(429);
});
