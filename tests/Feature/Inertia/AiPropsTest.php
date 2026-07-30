<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
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

function fakeGenerationPaused(bool $paused): void
{
    $analyses = Mockery::mock(AnalysisService::class);
    $analyses->shouldReceive('generationPaused')->andReturn($paused);
    app()->instance(AnalysisService::class, $analyses);
}

it('keeps the catching-up signal a closure so a partial reload can skip it', function (): void {
    expect(aiPropsFor(User::factory()->create())['aiCatchingUp'])->toBeInstanceOf(Closure::class);
});

it('never asks the pipeline about a guest for the catching-up signal', function (): void {
    $analyses = Mockery::mock(AnalysisService::class);
    $analyses->shouldNotReceive('generationPaused');
    app()->instance(AnalysisService::class, $analyses);

    expect((aiPropsFor(null)['aiCatchingUp'])())->toBeFalse();
});

it('is false while generation is globally paused, regardless of pending narration', function (): void {
    fakeGenerationPaused(true);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();
});

it('is false when every activity is fully narrated', function (): void {
    fakeGenerationPaused(false);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::factory()->done()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
        ]);
    }

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();
});

it('is true when an activity has a queued narration row', function (): void {
    fakeGenerationPaused(false);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeTrue();
});

it('is true when a failed narration row is still under the self-heal retry budget', function (): void {
    fakeGenerationPaused(false);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->failed()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeTrue();
});

it('is false when a narration row is dead-lettered and needs a manual re-arm', function (): void {
    fakeGenerationPaused(false);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->failed()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();
});

it('ignores another user\'s pending narration', function (): void {
    fakeGenerationPaused(false);

    $otherUser = User::factory()->create();
    $activity = Activity::factory()->for($otherUser)->create();
    Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    $user = User::factory()->create();
    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();
});
