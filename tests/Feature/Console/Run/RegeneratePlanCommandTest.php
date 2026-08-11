<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('regenerates the plan for every user', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->artisan('plan:regenerate')
        ->expectsOutputToContain('Regenerated the plan for 2 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $a->id)->exists())->toBeTrue()
        ->and(PlannedSession::query()->where('user_id', $b->id)->exists())->toBeTrue();
});

it('limits to a single user via --user', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->artisan("plan:regenerate --user={$a->id}")
        ->expectsOutputToContain('Regenerated the plan for 1 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $a->id)->exists())->toBeTrue()
        ->and(PlannedSession::query()->where('user_id', $b->id)->exists())->toBeFalse();
});
