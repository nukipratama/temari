<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Run\Plan\PlanRegenerateCooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports no cooldown before one is started', function (): void {
    $user = User::factory()->create();

    expect(app(PlanRegenerateCooldown::class)->remaining($user))->toBeNull();
});

it('reports a cooldown once started, scoped per user', function (): void {
    $cooldown = app(PlanRegenerateCooldown::class);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $cooldown->start($user);

    expect($cooldown->remaining($user))
        ->toBeInt()
        ->toBeGreaterThan(0)
        ->and($cooldown->remaining($otherUser))->toBeNull();
});
