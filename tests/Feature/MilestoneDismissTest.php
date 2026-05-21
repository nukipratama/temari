<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('nulls the milestone_payload for the user\'s own activity', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create([
        'milestones_detected_at' => now(),
        'milestone_payload' => [['kind' => 'pr', 'label' => 'PR', 'body' => 'x', 'priority' => 100]],
    ]);

    $this->actingAs($user)->postJson("/api/milestones/{$activity->id}/dismiss")
        ->assertSuccessful()
        ->assertJson(['ok' => true]);

    $activity->refresh();
    expect($activity->milestone_payload)->toBeNull()
        ->and($activity->milestones_detected_at)->not->toBeNull();
});

it('returns 404 when dismissing an activity that belongs to another user', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $activity = Activity::factory()->for($owner)->create([
        'milestone_payload' => [['kind' => 'pr']],
    ]);

    $this->actingAs($intruder)->postJson("/api/milestones/{$activity->id}/dismiss")
        ->assertNotFound();
});
