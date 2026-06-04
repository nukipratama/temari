<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('requires authentication', function (): void {
    $this->post(route('strava.sync'))->assertRedirect(route('login'));
});

it('queues a full sync for the signed-in athlete and flashes a notice', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('strava.sync'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(
        SyncActivitiesJob::class,
        fn (SyncActivitiesJob $job): bool => $job->userId === $user->id && $job->stravaActivityId === null,
    );
});

it('throttles rapid taps to two per minute', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('strava.sync'))->assertRedirect();
    $this->actingAs($user)->post(route('strava.sync'))->assertRedirect();
    $this->actingAs($user)->post(route('strava.sync'))->assertStatus(429);
});
