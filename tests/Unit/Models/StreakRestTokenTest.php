<?php

declare(strict_types=1);

use App\Models\StreakRestToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts the week columns to shift-free dates', function (): void {
    $token = StreakRestToken::factory()->create([
        'earned_for_week_ending' => '2026-05-24',
        'spent_for_week_ending' => '2026-05-31',
    ]);

    expect($token->refresh()->earned_for_week_ending->toDateString())->toBe('2026-05-24')
        ->and($token->spent_for_week_ending?->toDateString())->toBe('2026-05-31')
        ->and($token->toArray()['earned_for_week_ending'])->toBe('2026-05-24');
});

it('counts only unspent tokens toward the balance', function (): void {
    $user = User::factory()->create();
    StreakRestToken::factory()->for($user)->create(['earned_for_week_ending' => '2026-05-10']);
    StreakRestToken::factory()->for($user)->create(['earned_for_week_ending' => '2026-05-17']);
    StreakRestToken::factory()->for($user)
        ->spentFor(Carbon::parse('2026-05-31'))
        ->create(['earned_for_week_ending' => '2026-05-24']);

    expect(StreakRestToken::unspentCountForUser($user->id))->toBe(2);
});

it('reports forgiven week endings as a lookup keyed by date', function (): void {
    $user = User::factory()->create();
    StreakRestToken::factory()->for($user)->create(['earned_for_week_ending' => '2026-05-10']);
    StreakRestToken::factory()->for($user)
        ->spentFor(Carbon::parse('2026-05-31'))
        ->create(['earned_for_week_ending' => '2026-05-17']);

    expect(StreakRestToken::forgivenWeekEndings($user->id))->toBe(['2026-05-31' => true]);
});

it('keeps one user\'s tokens out of another\'s balance', function (): void {
    $user = User::factory()->create();
    StreakRestToken::factory()->create(['earned_for_week_ending' => '2026-05-10']);

    expect(StreakRestToken::unspentCountForUser($user->id))->toBe(0)
        ->and(StreakRestToken::forgivenWeekEndings($user->id))->toBe([]);
});
