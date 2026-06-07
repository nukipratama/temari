<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Support\Carbon;

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $unlock = UserUnlock::factory()->for($user)->create();

    expect($unlock->user)->toBeInstanceOf(User::class)
        ->and($unlock->user->is($user))->toBeTrue();
});

it('casts unlocked_at to Carbon and metadata to array', function (): void {
    $unlock = UserUnlock::factory()->create([
        'unlocked_at' => '2026-05-11 10:00:00',
        'metadata' => ['source' => 'pr_engine'],
    ]);

    expect($unlock->unlocked_at)->toBeInstanceOf(Carbon::class)
        ->and($unlock->unlocked_at->toDateTimeString())->toBe('2026-05-11 10:00:00')
        ->and($unlock->metadata)->toBe(['source' => 'pr_engine']);
});
