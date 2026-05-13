<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Llm\LlmNarratorException;
use App\Services\Run\Story\BriefingResult;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use App\Services\Run\Story\Narrators\FallbackBriefingNarrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

it('returns the primary result when the primary succeeds', function (): void {
    $primary = Mockery::mock(BriefingNarrator::class);
    $secondary = Mockery::mock(BriefingNarrator::class);

    $expected = new BriefingResult(
        vibeState: 'fresh',
        vibeLabel: 'Fresh',
        vibeEmoji: '✨',
        headlineLine: 'primary headline',
        suggestionLine: 'primary suggestion',
        recoveryLabel: 'Pemulihan: oke',
        recoveryTone: 'positive',
        streakLabel: null,
        sigilPattern: 'ssss',
        accessory: null,
        mood: 'glow',
    );
    $primary->shouldReceive('generate')->once()->andReturn($expected);
    $secondary->shouldNotReceive('generate');

    $fallback = new FallbackBriefingNarrator($primary, $secondary);
    $result = $fallback->generate(new User(), Carbon::today());

    expect($result)->toBe($expected)
        ->and($result->degraded)->toBeFalse();
});

it('falls back to the secondary and flips degraded when the primary throws', function (): void {
    Log::spy();

    $user = User::factory()->make(['id' => 42, 'name' => 'Ada']);
    $primary = Mockery::mock(BriefingNarrator::class);
    $secondary = Mockery::mock(BriefingNarrator::class);

    $secondaryResult = new BriefingResult(
        vibeState: 'hibernating',
        vibeLabel: 'Hibernasi',
        vibeEmoji: '😴',
        headlineLine: 'rule-based headline',
        suggestionLine: 'rule-based suggestion',
        recoveryLabel: 'Pemulihan: cukup',
        recoveryTone: 'neutral',
        streakLabel: null,
        sigilPattern: 'dddd',
        accessory: 'mata-ngantuk',
        mood: 'dim',
    );

    $primary->shouldReceive('generate')->once()->andThrow(new LlmNarratorException('Azure 500'));
    $secondary->shouldReceive('generate')->once()->andReturn($secondaryResult);

    $fallback = new FallbackBriefingNarrator($primary, $secondary);
    $result = $fallback->generate($user, Carbon::today());

    expect($result->headlineLine)->toBe('rule-based headline')
        ->and($result->degraded)->toBeTrue();
});
