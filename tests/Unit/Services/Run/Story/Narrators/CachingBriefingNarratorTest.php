<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Run\Story\BriefingResult;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use App\Services\Run\Story\Narrators\CachingBriefingNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('caches the inner narrator result by (user, date) and reuses it on subsequent calls', function (): void {
    Cache::flush();
    $user = User::factory()->create();
    $inner = Mockery::mock(BriefingNarrator::class);

    $expected = new BriefingResult(
        vibeState: 'bouncy',
        vibeLabel: 'Bouncy',
        vibeEmoji: '🦘',
        headlineLine: 'cached line',
        suggestionLine: 'cached suggestion',
        recoveryLabel: 'Pemulihan: oke',
        recoveryTone: 'positive',
        streakLabel: null,
        sigilPattern: 'orct',
        accessory: 'headband',
        mood: 'bouncy',
    );

    // Inner only invoked once even though we call twice.
    $inner->shouldReceive('generate')->once()->andReturn($expected);

    $caching = new CachingBriefingNarrator($inner, ttlSeconds: 3600);
    $today = Carbon::today();

    $first = $caching->generate($user, $today);
    $second = $caching->generate($user, $today);

    expect($first->headlineLine)->toBe('cached line')
        ->and($second->headlineLine)->toBe('cached line');
});

it('carries the degraded flag across cache hits', function (): void {
    Cache::flush();
    $user = User::factory()->create();
    $inner = Mockery::mock(BriefingNarrator::class);

    $degraded = new BriefingResult(
        vibeState: 'fresh',
        vibeLabel: 'Fresh',
        vibeEmoji: '✨',
        headlineLine: 'fallback hit',
        suggestionLine: 's',
        recoveryLabel: 'r',
        recoveryTone: 'neutral',
        streakLabel: null,
        sigilPattern: 'ssss',
        accessory: null,
        mood: 'glow',
        degraded: true,
    );
    $inner->shouldReceive('generate')->once()->andReturn($degraded);

    $caching = new CachingBriefingNarrator($inner, ttlSeconds: 3600);
    $caching->generate($user, Carbon::today());
    $cached = $caching->generate($user, Carbon::today());

    expect($cached->degraded)->toBeTrue();
});
