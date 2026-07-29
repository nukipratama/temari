<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\Inertia\AiProps;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function aiPropsFor(?User $user): array
{
    return app(AiProps::class)->forUser($user);
}

it('keeps the pause signal a closure so a partial reload can skip it', function (): void {
    expect(aiPropsFor(User::factory()->create())['aiPaused'])->toBeInstanceOf(Closure::class);
});

it('never asks the pipeline about a guest', function (): void {
    $analyses = Mockery::mock(AnalysisService::class);
    $analyses->shouldNotReceive('generationPaused');
    app()->instance(AnalysisService::class, $analyses);

    expect((aiPropsFor(null)['aiPaused'])())->toBeFalse();
});

it('shares the pause fact for a signed-in user', function (bool $paused): void {
    $analyses = Mockery::mock(AnalysisService::class);
    $analyses->shouldReceive('generationPaused')->andReturn($paused);
    app()->instance(AnalysisService::class, $analyses);

    expect((aiPropsFor(User::factory()->create())['aiPaused'])())->toBe($paused);
})->with([
    'generating' => [false],
    'paused' => [true],
]);
